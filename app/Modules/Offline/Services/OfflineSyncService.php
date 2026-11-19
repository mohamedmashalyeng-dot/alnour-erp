<?php

namespace App\Modules\Offline\Services;

use App\Models\User;
use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Catalog\Models\Product;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Services\Settings;
use App\Modules\Core\Services\TaxCalculator;
use App\Modules\Customers\Models\Customer;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Offline\Models\PosDevice;
use App\Modules\Offline\Models\SyncConflict;
use App\Modules\Offline\Models\SyncLog;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Services\SaleService;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Offline POS synchronization (plain HTTPS request/response — no WebSockets or workers needed).
 *
 * Guarantees:
 *  - idempotent: a sale UUID is processed once; re-sending returns the original result;
 *  - every sale is re-validated server-side (permissions, branch, products, discounts, payments);
 *  - the device is bound to one branch; a sale for another branch is rejected;
 *  - stock is never silently overwritten: sales that oversell are either accepted and flagged for
 *    review (policy "review", default — the goods already left the shop) or rejected into the
 *    review queue (policy "reject"); prices that differ from the catalog are flagged for review.
 */
class OfflineSyncService
{
    public const COOKIE = 'pos_device';

    public function __construct(
        private SaleService $sales,
        private BranchAccess $branches,
        private Settings $settings,
        private TaxCalculator $tax,
        private AuditLogger $audit,
    ) {}

    /** @return array{0: PosDevice, 1: string} device and its one-time secret (goes into an HttpOnly cookie only) */
    public function registerDevice(string $name, int $branchId, ?string $userAgent = null): array
    {
        Gate::authorize('sales.create');
        $this->branches->authorizeOperational($branchId);
        $secret = Str::random(64);
        $device = PosDevice::create([
            'uuid' => (string) Str::uuid(), 'name' => mb_substr(trim($name) ?: 'POS', 0, 100), 'user_id' => auth()->id(),
            'branch_id' => $branchId, 'token_hash' => hash('sha256', $secret), 'user_agent' => mb_substr((string) $userAgent, 0, 250), 'last_seen_at' => now(),
        ]);
        $this->audit->log('offline.device_registered', $device, [], ['name' => $device->name], $branchId);

        return [$device, $secret];
    }

    /** Resolve and verify the device from its cookie value "uuid|secret". */
    public function device(?string $cookie, User $user): PosDevice
    {
        [$uuid, $secret] = array_pad(explode('|', (string) $cookie, 2), 2, '');
        $device = $uuid ? PosDevice::where('uuid', $uuid)->first() : null;
        if (! $device || ! hash_equals($device->token_hash, hash('sha256', $secret)) || ! $device->isActive()) {
            throw new AuthorizationException(__('This device is not registered for offline sales, or it was revoked.'));
        }
        if (! $user->can('sales.create') || ! $this->branches->canAccess($device->branch_id, $user)) {
            throw new AuthorizationException(__('You are not authorized to sell on this device.'));
        }
        $device->forceFill(['last_seen_at' => now()])->save();

        return $device;
    }

    public function revoke(PosDevice $device): void
    {
        Gate::authorize('sync.review');
        $this->branches->authorize($device->branch_id);
        $device->forceFill(['revoked_at' => now(), 'revoked_by' => auth()->id()])->save();
        $this->audit->log('offline.device_revoked', $device, [], [], $device->branch_id);
    }

    /** Minimal data the offline POS needs: catalog, prices, stock snapshot, customers, settings. */
    public function bootstrap(PosDevice $device, User $user): array
    {
        $stock = Inventory::where('branch_id', $device->branch_id)->get(['product_id', 'quantity', 'reserved_quantity'])->keyBy('product_id');
        $products = Product::where('is_active', true)->orderBy('name')
            ->get(['id', 'name', 'name_en', 'sku', 'barcode', 'qr_code', 'selling_price', 'track_stock', 'tax_rate_id'])
            ->map(fn (Product $p) => [
                'id' => $p->id, 'name' => $p->name, 'name_en' => $p->name_en, 'sku' => $p->sku, 'barcode' => $p->barcode, 'qr' => $p->qr_code,
                'price' => $p->selling_price, 'track' => $p->track_stock, 'tax_bp' => $this->tax->rateFor($p),
                'stock' => isset($stock[$p->id]) ? $stock[$p->id]->quantity - $stock[$p->id]->reserved_quantity : 0,
            ]);
        $customers = $user->can('customers.view')
            ? Customer::where('is_active', true)->orderBy('name')->get(['id', 'name', 'phone'])->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'phone' => $c->phone])
            : collect();

        return [
            'version' => now()->toIso8601String(),
            'device' => ['uuid' => $device->uuid, 'name' => $device->name, 'branch_id' => $device->branch_id, 'branch' => $device->branch->displayName()],
            'user' => ['id' => $user->id, 'name' => $user->name, 'can_discount' => $user->can('sales.discount'),
                'max_discount_percent' => $user->can('sales.discount-unlimited') ? 100 : (int) $this->settings->get('max_discount_percent')],
            'settings' => [
                'tax_enabled' => $this->tax->enabled(), 'prices_include_tax' => $this->tax->pricesIncludeTax(),
                'walk_in' => $this->settings->bool('walk_in_customer_enabled'), 'company' => $this->settings->get('company_name'),
                'currency' => ['decimals' => (int) config('erp.currency.decimals'), 'symbol' => config('erp.currency.symbol')],
            ],
            'products' => $products->values(),
            'customers' => $customers->values(),
        ];
    }

    /**
     * @param  list<array>  $sales  offline sales from the device queue
     * @return list<array{uuid:string, status:string, invoice_number?:string, message?:string}>
     */
    public function sync(PosDevice $device, User $user, array $sales): array
    {
        $results = [];
        foreach (array_slice($sales, 0, 50) as $payload) {
            $results[] = $this->syncOne($device, $user, is_array($payload) ? $payload : []);
        }
        $device->forceFill(['last_sync_at' => now()])->save();

        return $results;
    }

    private function syncOne(PosDevice $device, User $user, array $p): array
    {
        $uuid = (string) ($p['uuid'] ?? '');
        $hash = hash('sha256', json_encode($p));
        try {
            Validator::make($p, [
                'uuid' => ['required', 'uuid'],
                'branch_id' => ['required', 'integer'],
                'created_at' => ['required', 'date'],
                'customer_id' => ['nullable', 'integer'],
                'discount' => ['nullable', 'integer', 'min:0'],
                'notes' => ['nullable', 'string', 'max:500'],
                'items' => ['required', 'array', 'min:1', 'max:200'],
                'items.*.product_id' => ['required', 'integer'],
                'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
                'items.*.unit_price' => ['required', 'integer', 'min:0'],
                'items.*.discount' => ['nullable', 'integer', 'min:0'],
                'payments' => ['present', 'array', 'max:10'],
                'payments.*.method' => ['required', 'in:cash,card,bank_transfer'],
                'payments.*.amount' => ['required', 'integer', 'min:1'],
                'payments.*.tendered' => ['nullable', 'integer', 'min:0'],
                'payments.*.reference' => ['nullable', 'string', 'max:100'],
            ])->validate();
        } catch (ValidationException $e) {
            return $this->log($device, $user, $uuid ?: (string) Str::uuid(), 'failed', collect($e->errors())->flatten()->first(), null, $hash);
        }

        // Idempotency: already synced (from this or a previous attempt) → same answer, nothing new.
        if ($existing = Sale::where('uuid', $uuid)->first()) {
            return $this->log($device, $user, $uuid, 'duplicate', null, $existing, $hash)
                + ['invoice_number' => $existing->invoice_number, 'review' => (bool) $existing->needs_review];
        }

        if ((int) $p['branch_id'] !== $device->branch_id) {
            return $this->reject($device, $user, $p, 'rejected', __('The sale belongs to another branch than this device.'), $hash);
        }

        $soldAt = Carbon::parse($p['created_at']);
        $maxAge = (int) $this->settings->get('offline_max_age_days', 7);
        if ($soldAt->isAfter(now()->addMinutes(10)) || $soldAt->isBefore(now()->subDays($maxAge))) {
            return $this->reject($device, $user, $p, 'rejected', __('The sale date is outside the accepted offline window (:days days).', ['days' => $maxAge]), $hash);
        }

        // Conflict detection against live data.
        $products = Product::whereIn('id', array_column($p['items'], 'product_id'))->get()->keyBy('id');
        $stock = Inventory::where('branch_id', $device->branch_id)->whereIn('product_id', $products->keys())->get()->keyBy('product_id');
        $shortages = [];
        $priceChanges = [];
        foreach ($p['items'] as $item) {
            $product = $products[$item['product_id']] ?? null;
            if (! $product) {
                return $this->reject($device, $user, $p, 'rejected', __('A product in this sale no longer exists.'), $hash);
            }
            $available = isset($stock[$product->id]) ? $stock[$product->id]->quantity - $stock[$product->id]->reserved_quantity : 0;
            if ($product->track_stock && $available < $item['quantity']) {
                $shortages[] = ['product_id' => $product->id, 'sku' => $product->sku, 'requested' => $item['quantity'], 'available' => $available];
            }
            if ((int) $item['unit_price'] !== $product->selling_price) {
                $priceChanges[] = ['product_id' => $product->id, 'sku' => $product->sku, 'offline_price' => (int) $item['unit_price'], 'catalog_price' => $product->selling_price];
            }
        }

        $policy = $this->settings->get('offline_oversell_policy', 'review');
        if ($shortages && $policy === 'reject') {
            return $this->reject($device, $user, $p, 'insufficient_stock', __('Not enough stock on the server; the sale was not recorded and needs review.'), $hash, ['shortages' => $shortages]);
        }

        try {
            $sale = DB::transaction(function () use ($p, $device, $soldAt, $shortages, $priceChanges, $user) {
                $sale = $this->sales->complete([
                    'uuid' => $p['uuid'], 'branch_id' => $device->branch_id, 'customer_id' => $p['customer_id'] ?? null,
                    'discount' => $p['discount'] ?? 0, 'notes' => trim(($p['notes'] ?? '').' ['.__('Offline sale').']'),
                    'items' => array_map(fn ($i) => ['product_id' => (int) $i['product_id'], 'quantity' => (int) $i['quantity'], 'discount' => (int) ($i['discount'] ?? 0)], $p['items']),
                    'payments' => array_map(fn ($x) => array_filter(['method' => $x['method'], 'amount' => (int) $x['amount'],
                        'tendered' => isset($x['tendered']) ? (int) $x['tendered'] : null, 'reference' => $x['reference'] ?? null], fn ($v) => $v !== null), $p['payments']),
                ], [
                    'offline' => true, 'device_id' => $device->id, 'sold_at' => $soldAt,
                    'unit_prices' => collect($p['items'])->mapWithKeys(fn ($i) => [(int) $i['product_id'] => (int) $i['unit_price']])->all(),
                    'allow_negative' => $shortages !== [],
                ]);
                if ($shortages || $priceChanges) {
                    $sale->forceFill(['needs_review' => true])->save();
                    foreach (array_filter(['insufficient_stock' => $shortages, 'price_changed' => $priceChanges]) as $type => $details) {
                        SyncConflict::create(['device_id' => $device->id, 'branch_id' => $device->branch_id, 'user_id' => $user->id, 'client_uuid' => $p['uuid'],
                            'sale_id' => $sale->id, 'type' => $type, 'details' => $details, 'status' => 'open']);
                    }
                }

                return $sale;
            });
        } catch (AuthorizationException $e) {
            return $this->reject($device, $user, $p, 'rejected', $e->getMessage() ?: __('Not authorized.'), $hash);
        } catch (ValidationException $e) {
            return $this->reject($device, $user, $p, 'rejected', (string) collect($e->errors())->flatten()->first(), $hash);
        }

        $review = $shortages || $priceChanges;

        return $this->log($device, $user, $uuid, $review ? 'conflict' : 'synced', $review ? __('Synced; flagged for review.') : null, $sale, $hash)
            + ['invoice_number' => $sale->invoice_number, 'review' => $review];
    }

    /** Record a sale that could not be posted into the review queue (payload kept for manual handling). */
    private function reject(PosDevice $device, User $user, array $p, string $type, string $message, string $hash, array $details = []): array
    {
        SyncConflict::firstOrCreate(['client_uuid' => $p['uuid'], 'type' => $type === 'insufficient_stock' ? 'insufficient_stock' : 'rejected', 'sale_id' => null], [
            'device_id' => $device->id, 'branch_id' => $device->branch_id, 'user_id' => $user->id,
            'details' => $details + ['message' => $message], 'payload' => $p, 'status' => 'open',
        ]);

        return $this->log($device, $user, $p['uuid'], 'rejected', $message, null, $hash) + ['review' => true];
    }

    private function log(PosDevice $device, User $user, string $uuid, string $status, ?string $message, ?Sale $sale, string $hash): array
    {
        SyncLog::create(['device_id' => $device->id, 'user_id' => $user->id, 'client_uuid' => $uuid, 'status' => $status,
            'message' => $message ? mb_substr($message, 0, 250) : null, 'sale_id' => $sale?->id, 'payload_hash' => $hash]);

        // Status the device shows: synced | review (needs manual review) | failed (fix & retry)
        $clientStatus = match ($status) {
            'synced', 'duplicate' => 'synced',
            'conflict', 'rejected' => 'review',
            default => 'failed',
        };

        return array_filter(['uuid' => $uuid, 'status' => $clientStatus, 'message' => $message], fn ($v) => $v !== null);
    }

    public function resolve(SyncConflict $conflict, string $resolution): SyncConflict
    {
        Gate::authorize('sync.review');
        $this->branches->authorize($conflict->branch_id);
        if (trim($resolution) === '') {
            throw ValidationException::withMessages(['resolution' => __('Describe how the conflict was resolved.')]);
        }

        return DB::transaction(function () use ($conflict, $resolution) {
            $c = SyncConflict::lockForUpdate()->findOrFail($conflict->id);
            $c->update(['status' => 'resolved', 'resolution' => mb_substr($resolution, 0, 255), 'resolved_by' => auth()->id(), 'resolved_at' => now()]);
            if ($c->sale_id && ! SyncConflict::where('sale_id', $c->sale_id)->where('status', 'open')->exists()) {
                Sale::whereKey($c->sale_id)->update(['needs_review' => false]);
            }
            $this->audit->log('offline.conflict_resolved', $c, [], ['resolution' => $resolution], $c->branch_id);

            return $c;
        });
    }
}
