<?php

namespace Tests\Feature\Ops;

use App\Modules\Branches\Models\Branch;
use App\Modules\Catalog\Models\Product;
use App\Modules\Core\Services\BackupService;
use App\Modules\Core\Services\IntegrityChecker;
use App\Modules\Core\Services\Settings;
use App\Modules\Customers\Models\Customer;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Services\PayrollService;
use App\Modules\Sales\Services\ReturnService;
use App\Modules\Sales\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackupRestoreTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = storage_path('framework/testing/erp-test-'.Str::random(6).'.ndjson.gz');
        @mkdir(dirname($this->file), 0777, true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->file, $this->file.'.sha256'] as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    /** Realistic activity across modules: stock, sales, payments, return, loyalty, encrypted HR data. */
    private function activity(): array
    {
        app(Settings::class)->set('loyalty_enabled', true);
        $branch = Branch::factory()->create();
        $customer = Customer::factory()->create();
        $frame = Product::factory()->create(['selling_price' => 45000, 'name' => 'إطار تيتانيوم']);
        $this->stock($branch, $frame, 10);
        $this->actingAsRole('admin', [$branch]);
        $sale = app(SaleService::class)->complete(['uuid' => (string) Str::uuid(), 'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'items' => [['product_id' => $frame->id, 'quantity' => 2]], 'payments' => [['method' => 'cash', 'amount' => 50000]]]);
        $svc = app(ReturnService::class);
        $svc->complete($svc->create($sale, [['sale_item_id' => $sale->items()->first()->id, 'quantity' => 1, 'condition' => 'good', 'disposition' => 'restock']], 'Size'));
        $employee = app(PayrollService::class)->saveEmployee(['branch_id' => $branch->id, 'name' => 'Noura', 'base_salary' => 400000, 'is_active' => true]);
        $employee->forceFill(['national_id' => '2233445566'])->save();

        return compact('branch', 'customer', 'frame', 'sale', 'employee');
    }

    private function snapshot(): array
    {
        $out = [];
        foreach (['sales', 'sale_items', 'payments', 'payment_allocations', 'inventory', 'inventory_transactions', 'journal_entries', 'journal_lines',
            'loyalty_transactions', 'audit_logs', 'employees', 'salary_changes', 'products', 'customers'] as $t) {
            $out[$t] = DB::table($t)->count();
        }
        $out['debits'] = (int) DB::table('journal_lines')->sum('debit');
        $out['stock'] = (int) DB::table('inventory')->sum('quantity');

        return $out;
    }

    public function test_backup_restore_round_trip_with_integrity_checks(): void
    {
        $d = $this->activity();
        $this->artisan('erp:integrity')->assertExitCode(0);
        $before = $this->snapshot();

        $r = app(BackupService::class)->backup($this->file);
        $this->assertFileExists($this->file.'.sha256');
        $this->assertSame($before['sales'], $r['tables']['sales']);
        $this->assertArrayNotHasKey('sessions', $r['tables']);
        $this->assertStringNotContainsString('2233445566', gzdecode(file_get_contents($this->file)), 'Encrypted fields stay encrypted in backups');

        // Disaster: data lost/corrupted.
        DB::statement('PRAGMA defer_foreign_keys = ON');
        DB::table('inventory')->update(['quantity' => 999]);
        DB::table('loyalty_transactions')->delete();
        DB::table('products')->where('id', $d['frame']->id)->update(['name' => 'corrupted']);
        $this->assertFalse(collect(app(IntegrityChecker::class)->run())->every(fn ($c) => $c['ok']));

        $this->artisan('erp:restore', ['file' => $this->file])->assertExitCode(1); // --force required
        $this->artisan('erp:restore', ['file' => $this->file, '--force' => true])->assertExitCode(0);

        $this->assertSame($before, $this->snapshot());
        $this->assertSame('إطار تيتانيوم', Product::find($d['frame']->id)->name, 'Arabic text survives the round trip');
        $this->assertSame('2233445566', Employee::find($d['employee']->id)->national_id, 'Encrypted value decrypts after restore (same APP_KEY)');
        $this->artisan('erp:integrity')->assertExitCode(0);
    }

    public function test_tampered_or_unverified_backups_are_refused(): void
    {
        $this->activity();
        app(BackupService::class)->backup($this->file);
        $this->artisan('erp:restore', ['file' => $this->file, '--verify-only' => true])->assertExitCode(0);

        $bytes = file_get_contents($this->file);
        $bytes[strlen($bytes) - 10] = chr(ord($bytes[strlen($bytes) - 10]) ^ 0xFF);
        file_put_contents($this->file, $bytes);
        $this->artisan('erp:restore', ['file' => $this->file, '--force' => true])->expectsOutputToContain('Checksum mismatch')->assertExitCode(1);

        unlink($this->file.'.sha256');
        $this->artisan('erp:restore', ['file' => $this->file, '--force' => true])->assertExitCode(1);
    }

    public function test_integrity_detects_stock_drift_and_unbalanced_journals(): void
    {
        $this->activity();
        $checker = app(IntegrityChecker::class);
        $this->assertTrue(collect($checker->run())->every(fn ($c) => $c['ok']));

        DB::table('inventory')->limit(1)->update(['quantity' => DB::raw('quantity + 1')]);
        $line = DB::table('journal_lines')->first();
        DB::table('journal_lines')->where('id', $line->id)->update(['debit' => $line->debit + 1, 'credit' => $line->credit]);
        $r = $checker->run();
        $this->assertFalse($r['stock_matches_ledger']['ok']);
        $this->assertFalse($r['journal_entries_balanced']['ok']);
        $this->assertFalse($r['trial_balance']['ok']);
        $this->artisan('erp:integrity')->assertExitCode(1);
    }

    public function test_backup_command_writes_and_prunes(): void
    {
        $dir = app(BackupService::class)->directory();
        $old = $dir.'/erp-20000101-000000.ndjson.gz';
        file_put_contents($old, 'x');
        file_put_contents($old.'.sha256', 'x');
        $this->artisan('erp:backup', ['--keep' => 1])->assertExitCode(0);
        $this->assertFileDoesNotExist($old);
        $files = glob($dir.'/erp-*.ndjson.gz');
        $this->assertCount(1, $files);
        foreach ($files as $f) {
            @unlink($f);
            @unlink($f.'.sha256');
        }
    }
}
