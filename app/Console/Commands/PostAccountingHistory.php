<?php

namespace App\Console\Commands;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\AccountingPoster;
use App\Modules\Accounting\Services\LedgerService;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Enums\TransferStatus;
use App\Modules\Inventory\Models\InventoryTransaction;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Payments\Models\Payment;
use App\Modules\Purchasing\Enums\PurchaseStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Sales\Enums\SaleReturnStatus;
use App\Modules\Sales\Enums\SaleStatus;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleReturn;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Posts every existing (pre-accounting) document to the general ledger.
 * Idempotent: documents already posted are skipped, so it is safe to re-run.
 */
class PostAccountingHistory extends Command
{
    protected $signature = 'accounting:post-history {--dry-run : Only count what would be posted}';

    protected $description = 'Post historical sales, returns, payments, purchases and stock movements to the general ledger';

    public function handle(AccountingPoster $poster, LedgerService $ledger): int
    {
        $count = 0;
        $dry = (bool) $this->option('dry-run');
        $run = function (string $label, iterable $docs, \Closure $post) use (&$count, $dry) {
            $n = 0;
            foreach ($docs as $doc) {
                if (! $dry) {
                    DB::transaction(fn () => $post($doc));
                }
                $n++;
            }
            $count += $n;
            $this->line(sprintf('%-28s %d', $label, $n));
        };

        $run('Opening/receipts/adjustments', InventoryTransaction::whereIn('type', [MovementType::Opening->value, MovementType::Receipt->value, MovementType::AdjustmentIn->value, MovementType::AdjustmentOut->value])
            ->orderBy('id')->lazy(), fn ($tx) => $poster->inventoryAdjusted($tx));
        $run('Purchases received', PurchaseOrder::where('status', PurchaseStatus::Received->value)->orderBy('id')->lazy(), fn ($po) => $poster->purchaseReceived($po));
        $run('Transfers received', StockTransfer::where('status', TransferStatus::Received->value)->orderBy('id')->lazy(), fn ($t) => $poster->transferReceived($t));
        $run('Sales', Sale::whereIn('status', [SaleStatus::Completed->value, SaleStatus::PartiallyPaid->value, SaleStatus::Refunded->value, SaleStatus::Cancelled->value])
            ->orderBy('id')->lazy(), function (Sale $sale) use ($poster, $ledger) {
                $entry = $poster->sale($sale);
                if ($entry && $sale->status === SaleStatus::Cancelled && $entry->status === 'posted') {
                    $ledger->reverse($entry, __('Sale cancelled'), $sale->cancelled_at?->toDateString());
                }
            });
        $run('Customer returns', SaleReturn::where('status', SaleReturnStatus::Completed->value)->orderBy('id')->lazy(), fn ($r) => $poster->saleReturn($r));
        $run('Payments', Payment::where('status', 'posted')->orderBy('id')->lazy(), fn ($p) => $poster->payment($p));

        $this->info(($dry ? 'Would process ' : 'Processed ').$count.' documents. Journal entries now: '.JournalEntry::count());

        return self::SUCCESS;
    }
}
