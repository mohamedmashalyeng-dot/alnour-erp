<?php

namespace App\Modules\Imports\Services;

use App\Modules\Branches\Models\Branch;
use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Catalog\Enums\FrameMaterial;
use App\Modules\Catalog\Enums\FrameType;
use App\Modules\Catalog\Enums\Gender;
use App\Modules\Catalog\Enums\LensType;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\ProductService;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Support\Money;
use App\Modules\Imports\Models\ImportLog;
use App\Modules\Imports\Models\ImportRow;
use App\Modules\Inventory\Models\InventoryTransaction;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Reports\Services\ReportExporter;
use App\Modules\Suppliers\Models\Supplier;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Product import (Excel / CSV / Google Sheets). MySQL stays the source of truth: the
 * spreadsheet is staged into import_rows, validated in full, reviewed by the admin, and only
 * then written through ProductService / InventoryService (same rules as manual entry).
 *
 * Every step is chunked (config erp.imports.chunk) so large files work on shared hosting
 * without queue workers: the browser polls step(), and the `imports:run` cron command
 * advances any import that was left running.
 */
class ProductImportService
{
    /** Importable fields and the header names (Arabic / English) that auto-map to them. */
    public const FIELDS = [
        'product_id' => ['product id', 'id', 'رقم المنتج', 'معرف المنتج'],
        'name' => ['name', 'product name', 'product', 'الاسم', 'اسم المنتج', 'المنتج'],
        'name_en' => ['name en', 'english name', 'name (english)', 'الاسم بالانجليزي', 'الاسم الانجليزي'],
        'sku' => ['sku', 'code', 'item code', 'رمز الصنف', 'الكود', 'كود الصنف'],
        'barcode' => ['barcode', 'ean', 'upc', 'الباركود', 'باركود'],
        'qr_code' => ['qr', 'qr code', 'رمز qr', 'كيو ار'],
        'product_type' => ['type', 'product type', 'النوع', 'نوع المنتج'],
        'brand' => ['brand', 'العلامة التجارية', 'الماركة', 'براند'],
        'category' => ['category', 'التصنيف', 'الفئة', 'القسم'],
        'model' => ['model', 'الموديل', 'موديل'],
        'color' => ['color', 'colour', 'اللون'],
        'size' => ['size', 'المقاس', 'المقاس/الحجم'],
        'frame_type' => ['frame type', 'نوع الإطار'],
        'frame_material' => ['material', 'frame material', 'خامة الإطار', 'الخامة'],
        'lens_type' => ['lens type', 'نوع العدسة'],
        'gender' => ['gender', 'الفئة العمرية', 'الجنس'],
        'purchase_price' => ['purchase price', 'cost', 'cost price', 'سعر الشراء', 'التكلفة'],
        'selling_price' => ['selling price', 'price', 'retail price', 'سعر البيع', 'السعر'],
        'description' => ['description', 'الوصف'],
        'image_url' => ['image', 'image url', 'photo', 'الصورة', 'رابط الصورة'],
        'supplier' => ['supplier', 'vendor', 'المورد'],
        'stock' => ['stock', 'quantity', 'qty', 'opening stock', 'الكمية', 'المخزون', 'الرصيد'],
    ];

    /** Fields an admin may allow to be overwritten on existing products ("update approved fields"). */
    public const UPDATABLE = ['name', 'name_en', 'qr_code', 'brand', 'category', 'model', 'color', 'size', 'frame_type', 'frame_material',
        'lens_type', 'gender', 'purchase_price', 'selling_price', 'description', 'supplier', 'product_type'];

    public function __construct(
        private ProductService $products,
        private InventoryService $inventory,
        private GoogleSheetsSource $google,
        private SafeUrlFetcher $fetcher,
        private AuditLogger $audit,
    ) {}

    // ------------------------------------------------------------------ 1. start

    public function fromUpload(UploadedFile $file): ImportLog
    {
        Gate::authorize('imports.manage');
        validator(['file' => $file], ['file' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:'.config('erp.imports.max_file_kb')]])->validate();
        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, ['xlsx', 'csv'], true)) {
            // .xlsm/.xls and other formats are refused: macros are never accepted.
            throw ValidationException::withMessages(['file' => __('Only .xlsx and .csv files are accepted.')]);
        }
        $path = $file->storeAs('imports', Str::uuid().'.'.$ext, 'local');

        return $this->stage($ext, $path, mb_substr($file->getClientOriginalName(), 0, 200), $ext);
    }

    public function fromGoogleSheet(string $url): ImportLog
    {
        Gate::authorize('imports.manage');
        $csv = $this->google->csv($url);
        $path = 'imports/'.Str::uuid().'.csv';
        Storage::disk('local')->put($path, $csv);

        return $this->stage('csv', $path, mb_substr($url, 0, 200), 'google_sheet');
    }

    public function fromUrl(string $url): ImportLog
    {
        Gate::authorize('imports.manage');
        $res = $this->fetcher->fetch($url, (int) config('erp.imports.max_file_kb') * 1024,
            ['text/csv', 'text/plain', 'application/octet-stream', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
        $ext = str_contains($res['type'], 'spreadsheetml') ? 'xlsx' : 'csv';
        $path = 'imports/'.Str::uuid().'.'.$ext;
        Storage::disk('local')->put($path, $res['body']);

        return $this->stage($ext, $path, mb_substr($url, 0, 200), 'url');
    }

    /** Read the file (streaming) and stage every row. */
    private function stage(string $ext, string $path, string $name, string $sourceType): ImportLog
    {
        $full = Storage::disk('local')->path($path);
        $log = ImportLog::create([
            'uuid' => (string) Str::uuid(), 'user_id' => auth()->id(), 'entity' => 'products', 'source_type' => $sourceType,
            'source_name' => $name, 'source_path' => $path, 'source_checksum' => hash_file('sha256', $full),
            'status' => 'mapping', 'duplicate_strategy' => 'skip', 'options' => ['update_fields' => []],
        ]);

        $headers = null;
        $batch = [];
        $n = 0;
        $max = (int) config('erp.imports.max_rows');
        foreach ($this->readRows($full, $ext) as $cells) {
            if ($headers === null) {
                $headers = array_map(fn ($h) => mb_substr(trim((string) $h), 0, 100), $cells);
                if (count(array_filter($headers)) === 0) {
                    throw ValidationException::withMessages(['file' => __('The first row must contain column headers.')]);
                }

                continue;
            }
            if (count(array_filter($cells, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue; // skip blank lines
            }
            if (++$n > $max) {
                $log->update(['status' => 'failed', 'last_error' => __('The file has more than :max rows. Split it into smaller files.', ['max' => $max])]);
                throw ValidationException::withMessages(['file' => __('The file has more than :max rows. Split it into smaller files.', ['max' => $max])]);
            }
            $data = [];
            foreach ($headers as $i => $h) {
                $data[$i] = mb_substr(trim((string) ($cells[$i] ?? '')), 0, 2000);
            }
            $batch[] = ['import_log_id' => $log->id, 'row_number' => $n + 1, 'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
                'status' => 'pending', 'created_at' => now(), 'updated_at' => now()];
            if (count($batch) === 500) {
                ImportRow::insert($batch);
                $batch = [];
            }
        }
        if ($batch) {
            ImportRow::insert($batch);
        }
        if ($headers === null) {
            throw ValidationException::withMessages(['file' => __('The file is empty.')]);
        }

        $log->update(['headers' => $headers, 'total_rows' => $n, 'column_mapping' => $this->guessMapping($headers)]);
        $this->audit->log('import.uploaded', $log, [], ['source' => $sourceType, 'rows' => $n]);

        return $log;
    }

    /**
     * Stream rows as arrays of strings. Formula cells contribute their cached value as text only —
     * formulas are never evaluated and macros are never run (OpenSpout does not execute anything).
     *
     * @return \Generator<int, list<string>>
     */
    private function readRows(string $path, string $ext): \Generator
    {
        $reader = $ext === 'xlsx' ? new XlsxReader : new CsvReader;
        $reader->open($path);
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $cells = [];
                    foreach ($row->cells as $cell) {
                        // Keep the formula TEXT when no cached result exists so validation can flag it (never evaluated).
                        $v = $cell instanceof FormulaCell ? ($cell->getComputedValue() ?? $cell->getValue()) : $cell->getValue();
                        $cells[] = match (true) {
                            $v instanceof \DateTimeInterface => $v->format('Y-m-d'),
                            is_bool($v) => $v ? '1' : '0',
                            is_float($v) => rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.'),
                            default => (string) $v,
                        };
                    }
                    $cells[0] = isset($cells[0]) ? preg_replace('/^\xEF\xBB\xBF/', '', $cells[0]) : '';
                    yield $cells;
                }
                break; // first sheet only
            }
        } finally {
            $reader->close();
        }
    }

    /** @return array<int,string> column index => field */
    public function guessMapping(array $headers): array
    {
        $codes = Branch::pluck('code')->map(fn ($c) => strtolower($c))->all();
        $map = [];
        foreach ($headers as $i => $h) {
            $norm = mb_strtolower(trim(preg_replace('/\s+/u', ' ', str_replace(['_', '-'], ' ', $h))));
            foreach (self::FIELDS as $field => $aliases) {
                if (in_array($norm, $aliases, true) && ! in_array($field, $map, true)) {
                    $map[$i] = $field;
                    break;
                }
            }
            // "stock B01" / "B01" / "مخزون B01" → stock for that branch
            if (! isset($map[$i]) && preg_match('/^(?:stock|qty|مخزون|الكمية)?\s*:?\s*([a-z0-9_-]+)$/iu', $norm, $m) && in_array(strtolower($m[1]), $codes, true)) {
                $map[$i] = 'stock:'.strtoupper($m[1]);
            }
        }

        return $map;
    }

    // ------------------------------------------------------------------ 2. mapping & options

    /**
     * @param  array<int,string>  $mapping  column index => field ('' = ignore)
     */
    public function configure(ImportLog $log, array $mapping, string $strategy, array $updateFields, ?int $branchId): ImportLog
    {
        Gate::authorize('imports.manage');
        $this->assertStatus($log, ['mapping', 'validated']);
        $allowed = array_merge(array_keys(self::FIELDS), Branch::pluck('code')->map(fn ($c) => 'stock:'.$c)->all());
        $clean = [];
        foreach ($mapping as $i => $field) {
            if ($field === '' || $field === null) {
                continue;
            }
            if (! in_array($field, $allowed, true) || in_array($field, $clean, true)) {
                throw ValidationException::withMessages(['mapping' => __('Each ERP field can be mapped to one column only.')]);
            }
            $clean[(int) $i] = $field;
        }
        $fields = array_values($clean);
        if (! in_array('sku', $fields, true) && ! in_array('barcode', $fields, true) && ! in_array('product_id', $fields, true) && ! in_array('name', $fields, true)) {
            throw ValidationException::withMessages(['mapping' => __('Map at least the product name, SKU or barcode.')]);
        }
        if (! in_array($strategy, ['skip', 'update', 'new_only'], true)) {
            throw ValidationException::withMessages(['duplicate_strategy' => __('Invalid option.')]);
        }
        if (in_array('stock', $fields, true)) {
            if (! $branchId) {
                throw ValidationException::withMessages(['branch_id' => __('Choose the branch for the stock column.')]);
            }
            app(BranchAccess::class)->authorizeOperational($branchId);
        }

        $log->update([
            'column_mapping' => $clean,
            'duplicate_strategy' => $strategy,
            'options' => ['update_fields' => array_values(array_intersect($updateFields, self::UPDATABLE))],
            'branch_id' => $branchId,
            'status' => 'validating', 'validated_rows' => 0, 'valid_count' => 0, 'invalid_count' => 0, 'duplicate_count' => 0,
        ]);
        $log->rows()->update(['status' => 'pending', 'errors' => null, 'warnings' => null, 'sku_key' => null, 'barcode_key' => null, 'product_id' => null]);

        return $log->refresh();
    }

    // ------------------------------------------------------------------ 3. validate / 5. process (chunked)

    /** Advance an import by one chunk. Returns the refreshed log. */
    public function step(ImportLog $log): ImportLog
    {
        Gate::authorize('imports.manage');
        $log = ImportLog::findOrFail($log->id);

        return match ($log->status) {
            'validating' => $this->validateChunk($log),
            'processing' => $this->processChunk($log),
            default => $log,
        };
    }

    private function validateChunk(ImportLog $log): ImportLog
    {
        $rows = $log->rows()->where('status', 'pending')->orderBy('row_number')->limit((int) config('erp.imports.chunk'))->get();
        foreach ($rows as $row) {
            [$values, $errors, $warnings] = $this->normalize($log, $row->data);
            $status = $errors ? 'invalid' : 'valid';

            // Duplicates inside the file (earlier row with the same SKU / barcode).
            $dupInFile = ImportRow::where('import_log_id', $log->id)->where('row_number', '<', $row->row_number)
                ->where(fn ($q) => $q->when($values['sku'] ?? null, fn ($w, $s) => $w->orWhere('sku_key', mb_strtolower($s)))
                    ->when($values['barcode'] ?? null, fn ($w, $b) => $w->orWhere('barcode_key', $b)))
                ->when(! ($values['sku'] ?? null) && ! ($values['barcode'] ?? null), fn ($q) => $q->whereRaw('1 = 0'))
                ->value('row_number');
            if ($dupInFile) {
                $errors[] = __('Duplicate of row :row in this file.', ['row' => $dupInFile]);
                $status = 'invalid';
            }

            // Duplicates against the database.
            $existing = $this->findExisting($values);
            if ($status === 'valid' && $existing) {
                $status = 'duplicate';
                if ($log->duplicate_strategy === 'skip' || $log->duplicate_strategy === 'new_only') {
                    $warnings[] = __('Already exists (:sku): will be skipped.', ['sku' => $existing->sku]);
                } else {
                    $warnings[] = __('Already exists (:sku): approved fields will be updated.', ['sku' => $existing->sku]);
                }
                // A barcode/SKU belonging to two different existing products is a conflict.
                $other = $this->conflicting($values, $existing);
                if ($other) {
                    $errors[] = __('SKU and barcode point to two different existing products (:a, :b).', ['a' => $existing->sku, 'b' => $other->sku]);
                    $status = 'invalid';
                }
            } elseif ($status === 'valid' && ! empty($values['product_id'])) {
                $errors[] = __('Product ID :id does not exist.', ['id' => $values['product_id']]);
                $status = 'invalid';
            } elseif ($status === 'valid' && empty($values['name'])) {
                $errors[] = __('Name is required for new products.');
                $status = 'invalid';
            }

            $row->update([
                'status' => $status, 'errors' => $errors ?: null, 'warnings' => $warnings ?: null,
                'sku_key' => isset($values['sku']) ? mb_strtolower($values['sku']) : null, 'barcode_key' => $values['barcode'] ?? null,
                'product_id' => $existing?->id,
            ]);
        }

        $counts = $log->rows()->selectRaw("SUM(CASE WHEN status = 'valid' THEN 1 ELSE 0 END) as v, SUM(CASE WHEN status = 'invalid' THEN 1 ELSE 0 END) as i,
            SUM(CASE WHEN status = 'duplicate' THEN 1 ELSE 0 END) as d, SUM(CASE WHEN status <> 'pending' THEN 1 ELSE 0 END) as done")->first();
        $log->update([
            'valid_count' => (int) $counts->v, 'invalid_count' => (int) $counts->i, 'duplicate_count' => (int) $counts->d, 'validated_rows' => (int) $counts->done,
            'status' => (int) $counts->done >= $log->total_rows ? 'validated' : 'validating',
        ]);
        if ($log->status === 'validated') {
            $this->writeErrorReport($log);
        }

        return $log->refresh();
    }

    public function confirm(ImportLog $log): ImportLog
    {
        Gate::authorize('imports.manage');
        $this->assertStatus($log, ['validated']);
        $log->update(['status' => 'processing', 'started_at' => now(), 'processed_rows' => 0]);
        $this->audit->log('import.confirmed', $log, [], ['valid' => $log->valid_count, 'duplicates' => $log->duplicate_count, 'strategy' => $log->duplicate_strategy]);

        return $log->refresh();
    }

    public function cancel(ImportLog $log): ImportLog
    {
        Gate::authorize('imports.manage');
        if (in_array($log->status, ['completed', 'cancelled'], true)) {
            return $log;
        }
        $log->update(['status' => 'cancelled', 'completed_at' => now()]);
        $this->audit->log('import.cancelled', $log, [], ['processed' => $log->processed_rows]);

        return $log->refresh();
    }

    private function processChunk(ImportLog $log): ImportLog
    {
        $rows = $log->rows()->whereIn('status', ['valid', 'duplicate'])->orderBy('row_number')->limit((int) config('erp.imports.chunk'))->get();
        foreach ($rows as $row) {
            if (ImportLog::whereKey($log->id)->value('status') !== 'processing') {
                return $log->refresh(); // cancelled meanwhile
            }
            [$values, $errors] = $this->normalize($log, $row->data);
            try {
                $outcome = DB::transaction(fn () => $this->importRow($log, $row, $values));
                $row->update(['status' => $outcome['status'], 'product_id' => $outcome['product_id'], 'warnings' => $outcome['warnings'] ?: $row->warnings]);
            } catch (ValidationException $e) {
                $row->update(['status' => 'failed', 'errors' => collect($e->errors())->flatten()->all()]);
            } catch (\Throwable $e) {
                report($e);
                $row->update(['status' => 'failed', 'errors' => [__('Unexpected error: :msg', ['msg' => Str::limit($e->getMessage(), 150)])]]);
            }
        }

        $c = $log->rows()->selectRaw("SUM(CASE WHEN status = 'imported' THEN 1 ELSE 0 END) as created, SUM(CASE WHEN status = 'updated' THEN 1 ELSE 0 END) as updated,
            SUM(CASE WHEN status = 'skipped' THEN 1 ELSE 0 END) as skipped, SUM(CASE WHEN status IN ('failed','invalid') THEN 1 ELSE 0 END) as failed,
            SUM(CASE WHEN status IN ('valid','duplicate') THEN 1 ELSE 0 END) as remaining")->first();
        $done = (int) $c->remaining === 0;
        $log->update([
            'created_count' => (int) $c->created, 'updated_count' => (int) $c->updated, 'skipped_count' => (int) $c->skipped, 'failed_count' => (int) $c->failed,
            'processed_rows' => $log->total_rows - (int) $c->remaining,
            'status' => $done ? 'completed' : 'processing', 'completed_at' => $done ? now() : null,
        ]);
        if ($done) {
            $this->writeErrorReport($log->refresh());
            $this->audit->log('import.completed', $log, [], ['created' => (int) $c->created, 'updated' => (int) $c->updated, 'skipped' => (int) $c->skipped, 'failed' => (int) $c->failed]);
        }

        return $log->refresh();
    }

    /** @return array{status:string, product_id:?int, warnings:array} */
    private function importRow(ImportLog $log, ImportRow $row, array $values): array
    {
        $warnings = [];
        $existing = $this->findExisting($values);

        if ($existing) {
            if ($log->duplicate_strategy !== 'update') {
                return ['status' => 'skipped', 'product_id' => $existing->id, 'warnings' => []];
            }
            $allowed = $log->options['update_fields'] ?? [];
            $input = $this->formFromProduct($existing);
            foreach ($allowed as $field) {
                if (array_key_exists($field, $values) && $values[$field] !== null && $values[$field] !== '') {
                    $this->applyField($input, $field, $values[$field]);
                }
            }
            $product = $this->products->update($existing, $input);
            $status = 'updated';
        } else {
            $input = ['name' => $values['name'], 'product_type' => $values['product_type'] ?? ProductType::Frame->value,
                'purchase_price' => '0', 'selling_price' => '0', 'is_active' => true, 'track_stock' => true];
            foreach (array_keys(self::FIELDS) as $field) {
                if (! in_array($field, ['product_id', 'stock', 'image_url'], true) && isset($values[$field]) && $values[$field] !== '') {
                    $this->applyField($input, $field, $values[$field]);
                }
            }
            $product = $this->products->create($input);
            $status = 'imported';
        }

        // Opening stock per branch: only where the product has no stock history (never overwrites stock).
        foreach ($this->stockColumns($log, $values) as $branchId => $qty) {
            if ($qty <= 0) {
                continue;
            }
            $hasHistory = InventoryTransaction::where('branch_id', $branchId)->where('product_id', $product->id)->exists();
            if ($hasHistory) {
                $warnings[] = __('Stock not changed in :branch: the product already has stock movements there.', ['branch' => Branch::find($branchId)?->code]);

                continue;
            }
            $this->inventory->recordOpeningStock($branchId, [['product_id' => $product->id, 'quantity' => $qty]], __('Imported from :file', ['file' => $log->source_name]));
        }

        if (! empty($values['image_url'])) {
            try {
                $img = $this->fetcher->fetch($values['image_url'], 2_097_152, ['image/jpeg', 'image/png', 'image/webp']);
                $tmp = tempnam(sys_get_temp_dir(), 'img');
                file_put_contents($tmp, $img['body']);
                $ext = ['image/png' => 'png', 'image/webp' => 'webp'][$img['type']] ?? 'jpg';
                $this->products->addImage($product, new UploadedFile($tmp, 'import.'.$ext, $img['type'], null, true));
            } catch (\Throwable $e) {
                $warnings[] = __('Image not imported: :msg', ['msg' => Str::limit($e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage(), 120)]);
            }
        }

        return ['status' => $status, 'product_id' => $product->id, 'warnings' => $warnings];
    }

    // ------------------------------------------------------------------ normalization

    /** @return array{0: array<string,mixed>, 1: list<string>, 2: list<string>} */
    public function normalize(ImportLog $log, array $data): array
    {
        $v = [];
        $errors = [];
        $warnings = [];
        foreach ((array) $log->column_mapping as $i => $field) {
            $raw = (string) ($data[$i] ?? '');
            if (preg_match('/^[=+\-@]/', $raw) && ! preg_match('/^-?\d/', $raw)) {
                $warnings[] = __('Column :col looked like a formula and was imported as plain text.', ['col' => $log->headers[$i] ?? $i]);
                $raw = ltrim($raw, '=+@');
            }
            $v[$field] = $raw === '' ? null : $raw;
        }

        foreach (['purchase_price', 'selling_price'] as $p) {
            if (isset($v[$p])) {
                $clean = str_replace([' ', config('erp.currency.symbol'), (string) config('erp.currency.code')], '', $v[$p]);
                if (! Money::isValid($clean) || Money::parse($clean) < 0) {
                    $errors[] = __('Invalid :field: ":value".', ['field' => __(str_replace('_', ' ', $p)), 'value' => $v[$p]]);
                } else {
                    $v[$p] = Money::toDecimal(Money::parse($clean));
                }
            }
        }
        if (isset($v['sku']) && ! preg_match('/^[A-Za-z0-9\-_.\/]{1,64}$/', $v['sku'])) {
            $errors[] = __('Invalid SKU ":value" (letters, digits, - _ . / only).', ['value' => $v['sku']]);
        }
        if (isset($v['barcode'])) {
            $v['barcode'] = preg_replace('/\s+/', '', $v['barcode']);
            if (! preg_match('/^[A-Za-z0-9\-]{1,64}$/', $v['barcode'])) {
                $errors[] = __('Invalid barcode ":value".', ['value' => $v['barcode']]);
            }
        }
        if (isset($v['product_id']) && ! ctype_digit($v['product_id'])) {
            $errors[] = __('Invalid product ID ":value".', ['value' => $v['product_id']]);
        }
        foreach (['product_type' => ProductType::class, 'frame_type' => FrameType::class, 'frame_material' => FrameMaterial::class,
            'lens_type' => LensType::class, 'gender' => Gender::class] as $field => $enum) {
            if (isset($v[$field])) {
                $match = $this->enumValue($enum, $v[$field]);
                if ($match === null) {
                    $errors[] = __('Unknown value ":value" for :field.', ['value' => $v[$field], 'field' => __(str_replace('_', ' ', $field))]);
                } else {
                    $v[$field] = $match;
                }
            }
        }
        if (isset($v['image_url']) && ! str_starts_with($v['image_url'], 'https://')) {
            $warnings[] = __('Image link ignored: only https:// links from allowed hosts are supported.');
            $v['image_url'] = null;
        }
        foreach ($v as $field => $value) {
            if (($field === 'stock' || str_starts_with($field, 'stock:')) && $value !== null && ! preg_match('/^\d{1,7}$/', $value)) {
                $errors[] = __('Invalid quantity ":value" in :col.', ['value' => $value, 'col' => $field]);
            }
        }
        foreach (['name' => 255, 'name_en' => 255, 'model' => 100, 'color' => 64, 'size' => 32, 'qr_code' => 191, 'brand' => 255, 'category' => 255, 'supplier' => 255] as $f => $len) {
            if (isset($v[$f]) && mb_strlen($v[$f]) > $len) {
                $errors[] = __(':field is too long.', ['field' => __($f)]);
            }
        }

        return [$v, $errors, $warnings];
    }

    private function enumValue(string $enum, string $value): ?string
    {
        $needle = mb_strtolower(trim($value));
        foreach ($enum::cases() as $case) {
            if ($needle === $case->value || $needle === mb_strtolower($case->label()) || $needle === mb_strtolower(str_replace('_', ' ', $case->value))
                || $needle === mb_strtolower(trans($case->label(), [], 'en')) || $needle === mb_strtolower(trans($case->label(), [], 'ar'))) {
                return $case->value;
            }
        }

        return null;
    }

    private function findExisting(array $v): ?Product
    {
        if (! empty($v['product_id']) && ctype_digit((string) $v['product_id'])) {
            return Product::find((int) $v['product_id']);
        }
        if (! empty($v['sku']) && ($p = Product::where('sku', $v['sku'])->first())) {
            return $p;
        }
        if (! empty($v['barcode']) && ($p = Product::where('barcode', $v['barcode'])->first())) {
            return $p;
        }

        return null;
    }

    private function conflicting(array $v, Product $existing): ?Product
    {
        foreach (['sku', 'barcode'] as $f) {
            if (! empty($v[$f]) && ($p = Product::where($f, $v[$f])->first()) && $p->id !== $existing->id) {
                return $p;
            }
        }

        return null;
    }

    /** @return array<int,int> branch_id => quantity */
    private function stockColumns(ImportLog $log, array $v): array
    {
        $out = [];
        $codes = Branch::pluck('id', 'code');
        foreach ($v as $field => $qty) {
            if ($qty === null) {
                continue;
            }
            if ($field === 'stock' && $log->branch_id) {
                $out[$log->branch_id] = (int) $qty;
            } elseif (str_starts_with($field, 'stock:') && isset($codes[substr($field, 6)])) {
                $out[(int) $codes[substr($field, 6)]] = (int) $qty;
            }
        }

        return $out;
    }

    private function applyField(array &$input, string $field, string $value): void
    {
        match ($field) {
            'brand' => $input['brand_id'] = Brand::firstOrCreate(['name' => $value])->id,
            'category' => $input['category_id'] = (Category::where('name', $value)->orWhere('name_en', $value)->first() ?? Category::create(['name' => $value]))->id,
            'supplier' => $input['supplier_id'] = (Supplier::where('name', $value)->first() ?? Supplier::create(['name' => $value]))->id,
            default => $input[$field] = $value,
        };
    }

    private function formFromProduct(Product $p): array
    {
        return [
            'name' => $p->name, 'name_en' => $p->name_en, 'sku' => $p->sku, 'barcode' => $p->barcode, 'qr_code' => $p->qr_code,
            'product_type' => $p->product_type->value, 'category_id' => $p->category_id, 'brand_id' => $p->brand_id, 'supplier_id' => $p->supplier_id,
            'tax_rate_id' => $p->tax_rate_id, 'model' => $p->model, 'color' => $p->color, 'size' => $p->size,
            'frame_type' => $p->frame_type?->value, 'frame_material' => $p->frame_material?->value, 'lens_type' => $p->lens_type?->value,
            'gender' => $p->gender?->value, 'specifications' => $p->specifications ?? [],
            'purchase_price' => Money::toDecimal($p->purchase_price), 'selling_price' => Money::toDecimal($p->selling_price),
            'description' => $p->description, 'track_stock' => $p->track_stock, 'is_active' => $p->is_active,
        ];
    }

    private function assertStatus(ImportLog $log, array $allowed): void
    {
        if (! in_array(ImportLog::whereKey($log->id)->value('status'), $allowed, true)) {
            throw ValidationException::withMessages(['status' => __('This import cannot do that in its current state.')]);
        }
    }

    // ------------------------------------------------------------------ reports & export

    /** CSV of every row that has errors or warnings (row number, status, messages, original values). */
    public function writeErrorReport(ImportLog $log): void
    {
        $path = 'imports/'.$log->uuid.'-report.csv';
        $fh = fopen('php://temp', 'w+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, array_merge([__('Row'), __('Status'), __('Errors'), __('Warnings')], array_map(fn ($h) => ReportExporter::safe((string) $h), (array) $log->headers)), ',', '"', '\\');
        $log->rows()->where(fn ($q) => $q->whereNotNull('errors')->orWhereNotNull('warnings'))->orderBy('row_number')
            ->chunk(500, function ($rows) use ($fh) {
                foreach ($rows as $r) {
                    fputcsv($fh, array_merge([$r->row_number, __('import.'.$r->status), implode(' | ', (array) $r->errors), implode(' | ', (array) $r->warnings)],
                        array_map(fn ($c) => ReportExporter::safe((string) $c), $r->data)), ',', '"', '\\');
                }
            });
        rewind($fh);
        Storage::disk('local')->put($path, stream_get_contents($fh));
        fclose($fh);
        $log->update(['error_report_path' => $path]);
    }

    /** Export the catalog (with stock per branch) in the same column layout the importer understands. */
    public function exportProducts(string $format): StreamedResponse
    {
        Gate::authorize('products.view');
        $branches = Branch::orderBy('code')->get(['id', 'code']);
        $header = ['product id', 'sku', 'barcode', 'qr code', 'name', 'name en', 'product type', 'brand', 'category', 'model', 'color', 'size',
            'purchase price', 'selling price', 'supplier', 'description', ...$branches->map(fn ($b) => 'stock '.$b->code)->all()];
        $exporter = app(ReportExporter::class);
        $rows = [];
        Product::with(['brand:id,name', 'category:id,name', 'supplier:id,name', 'inventory:id,product_id,branch_id,quantity'])->orderBy('id')
            ->chunk(500, function ($products) use (&$rows, $branches) {
                foreach ($products as $p) {
                    $stock = $p->inventory->keyBy('branch_id');
                    $rows[] = ['label' => '', 'cells' => [$p->id, $p->sku, $p->barcode, $p->qr_code, $p->name, $p->name_en, $p->product_type->value, $p->brand?->name, $p->category?->name,
                        $p->model, $p->color, $p->size, Money::toDecimal($p->purchase_price), Money::toDecimal($p->selling_price), $p->supplier?->name, $p->description,
                        ...$branches->map(fn ($b) => (int) ($stock[$b->id]->quantity ?? 0))->all()]];
                }
            });
        $columns = [];
        foreach ($header as $i => $h) {
            $columns['c'.$i] = ['label' => $h, 'type' => 'text'];
        }
        $report = ['title' => 'Products', 'columns' => $columns, 'rows' => array_map(fn ($r) => array_combine(array_keys($columns), array_map(fn ($c) => (string) $c, $r['cells'])), $rows)];

        return $format === 'xlsx' ? $exporter->xlsx($report, 'products') : $exporter->csv($report, 'products');
    }
}
