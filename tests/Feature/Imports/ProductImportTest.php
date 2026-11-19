<?php

namespace Tests\Feature\Imports;

use App\Modules\Branches\Models\Branch;
use App\Modules\Catalog\Models\Product;
use App\Modules\Imports\Models\ImportLog;
use App\Modules\Imports\Services\ProductImportService;
use App\Modules\Imports\Services\SafeUrlFetcher;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class ProductImportTest extends TestCase
{
    use RefreshDatabase;

    private Branch $b1;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->b1 = Branch::factory()->create(['code' => 'B01']);
        Branch::factory()->create(['code' => 'B02']);
        $this->actingAsRole('admin', [$this->b1]);
    }

    private function csv(string $content, string $name = 'products.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "\xEF\xBB\xBF".$content);
    }

    private function drain(ImportLog $log): ImportLog
    {
        $svc = app(ProductImportService::class);
        for ($i = 0; $i < 50 && ($log = $svc->step($log))->isActive(); $i++) {
        }

        return $log;
    }

    public function test_full_import_with_arabic_headers_validation_duplicates_and_branch_stock(): void
    {
        $existing = Product::factory()->create(['sku' => 'EXIST-1', 'selling_price' => 100]);
        $csv = "اسم المنتج,رمز الصنف,الباركود,الماركة,التصنيف,سعر الشراء,سعر البيع,stock B01,B02\n"
            ."إطار أ,RB-1,4006381333931,Ray-Ban,إطارات,100.50,250,5,3\n"
            ."إطار ب,RB-2,,Ray-Ban,إطارات,abc,300,0,\n"           // invalid purchase price
            ."إطار مكرر,RB-1,,,,10,20,,\n"                          // duplicate SKU inside the file
            ."موجود,EXIST-1,,,,10,999,7,\n"                         // exists in DB → skipped
            ."خطر,RB-3,,,,=SUM(A1:A9),20,,\n";                       // formula-like price → treated as text → invalid
        $svc = app(ProductImportService::class);
        $log = $svc->fromUpload($this->csv($csv));

        $this->assertSame(5, $log->total_rows);
        $this->assertSame('mapping', $log->status);
        $this->assertSame(['name', 'sku', 'barcode', 'brand', 'category', 'purchase_price', 'selling_price', 'stock:B01', 'stock:B02'], array_values($log->column_mapping));

        $log = $this->drain($svc->configure($log, $log->column_mapping, 'skip', [], null));
        $this->assertSame('validated', $log->status);
        $this->assertSame(1, $log->valid_count);
        $this->assertSame(1, $log->duplicate_count);
        $this->assertSame(3, $log->invalid_count);
        $this->assertSame(1, Product::count(), 'Nothing written before confirmation');
        Storage::disk('local')->assertExists($log->error_report_path);

        $log = $this->drain($svc->confirm($log));
        $this->assertSame('completed', $log->status);
        $this->assertSame(1, $log->created_count);
        $this->assertSame(1, $log->skipped_count);

        $p = Product::where('sku', 'RB-1')->firstOrFail();
        $this->assertSame(10050, $p->purchase_price);
        $this->assertSame(25000, $p->selling_price);
        $this->assertSame('Ray-Ban', $p->brand->name);
        $this->assertSame(5, $this->qty($this->b1, $p));
        $this->assertSame(3, $this->qty(Branch::where('code', 'B02')->first(), $p));
        $this->assertSame(100, $existing->fresh()->selling_price, 'Skip strategy never overwrites');
        $this->assertDatabaseHas('audit_logs', ['event' => 'import.completed']);
    }

    public function test_update_strategy_changes_only_approved_fields_and_never_stock(): void
    {
        $p = Product::factory()->create(['sku' => 'UPD-1', 'name' => 'Old', 'selling_price' => 1000, 'color' => 'Red']);
        $this->stock($this->b1, $p, 4);
        $svc = app(ProductImportService::class);
        $log = $svc->fromUpload($this->csv("sku,name,selling price,color,stock\nUPD-1,New name,15.00,Blue,99\n"));
        $log = $this->drain($svc->configure($log, $log->column_mapping, 'update', ['selling_price'], $this->b1->id));
        $log = $this->drain($svc->confirm($log));

        $p->refresh();
        $this->assertSame(1, $log->updated_count);
        $this->assertSame(1500, $p->selling_price);
        $this->assertSame('Old', $p->name, 'Name not approved for update');
        $this->assertSame('Red', $p->color);
        $this->assertSame(4, $this->qty($this->b1, $p), 'Existing stock never overwritten');
        $this->assertNotEmpty($log->rows()->first()->warnings);
    }

    public function test_xlsx_import_and_macro_files_refused(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        $w = new Writer;
        $w->openToFile($path);
        $w->addRow(Row::fromValues(['name', 'sku', 'selling price']));
        $w->addRow(Row::fromValues(['Frame X', 'XL-1', 199.5]));
        $w->close();
        $svc = app(ProductImportService::class);
        $log = $svc->fromUpload(new UploadedFile($path, 'catalog.xlsx', null, null, true));
        $log = $this->drain($svc->confirm($this->drain($svc->configure($log, $log->column_mapping, 'skip', [], null))));
        $this->assertSame(19950, Product::where('sku', 'XL-1')->value('selling_price'));

        $this->expectException(ValidationException::class);
        $svc->fromUpload(UploadedFile::fake()->createWithContent('evil.xlsm', 'PK...'));
    }

    public function test_ssrf_protection(): void
    {
        $f = app(SafeUrlFetcher::class);
        SafeUrlFetcher::$resolver = fn ($h) => $h === 'docs.google.com' ? ['142.250.1.1'] : ['10.0.0.5'];
        try {
            foreach (['http://docs.google.com/x', 'https://127.0.0.1/x', 'https://evil.example.com/x', 'https://user:pw@docs.google.com/x',
                'https://docs.google.com:8443/x', 'file:///etc/passwd'] as $bad) {
                try {
                    $f->validate($bad);
                    $this->fail("Accepted $bad");
                } catch (ValidationException) {
                }
            }
            config(['erp.imports.allowed_hosts' => ['docs.google.com', 'internal.example.com']]);
            try {
                $f->validate('https://internal.example.com/sheet.csv'); // allowed host but private IP (DNS rebinding style)
                $this->fail('Private address accepted');
            } catch (ValidationException) {
            }
            $this->assertSame(['docs.google.com', '142.250.1.1'], $f->validate('https://docs.google.com/spreadsheets/d/x'));
        } finally {
            SafeUrlFetcher::$resolver = null;
        }
    }

    public function test_google_sheet_link_import(): void
    {
        SafeUrlFetcher::$resolver = fn () => ['142.250.1.1'];
        Http::fake(['docs.google.com/*' => Http::response("name,sku,selling price\nGS Frame,GS-1,50\n", 200, ['Content-Type' => 'text/csv'])]);
        try {
            $svc = app(ProductImportService::class);
            $log = $svc->fromGoogleSheet('https://docs.google.com/spreadsheets/d/1AbCdEfGhIjKlMnOpQrStUvWxYz0123456789/edit#gid=0');
            $this->assertSame('google_sheet', $log->source_type);
            $this->assertSame(1, $log->total_rows);
            Http::assertSent(fn ($r) => str_contains($r->url(), '/export?format=csv&gid=0'));
        } finally {
            SafeUrlFetcher::$resolver = null;
        }
        $this->expectException(ValidationException::class);
        $svc->fromGoogleSheet('https://example.com/not-a-sheet');
    }

    public function test_only_administrators_can_import(): void
    {
        $this->actingAsRole('branch-manager', [$this->b1]);
        $this->get(route('imports.index'))->assertForbidden();
        $this->expectException(AuthorizationException::class);
        app(ProductImportService::class)->fromUpload($this->csv("name\nx\n"));
    }

    public function test_cron_command_advances_imports_and_pages_render(): void
    {
        $svc = app(ProductImportService::class);
        $log = $svc->fromUpload($this->csv("name,sku\nCron Frame,CR-1\n"));
        $log = $svc->configure($log, $log->column_mapping, 'skip', [], null);
        $this->artisan('imports:run')->assertSuccessful();
        $this->assertSame('validated', $log->fresh()->status);
        $svc->confirm($log->fresh());
        $this->artisan('imports:run')->assertSuccessful();
        $this->assertSame('completed', $log->fresh()->status);

        $this->get(route('imports.index'))->assertOk();
        $this->get(route('imports.show', $log))->assertOk();
        $this->get(route('imports.export', ['format' => 'csv']))->assertOk();
        $this->get(route('imports.export', ['format' => 'xlsx']))->assertOk();
        $this->assertSame(1, Product::where('sku', 'CR-1')->count());
    }
}
