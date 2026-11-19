<?php

namespace App\Console\Commands;

use App\Modules\Core\Services\IntegrityChecker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ErpIntegrity extends Command
{
    protected $signature = 'erp:integrity';

    protected $description = 'Read-only consistency checks: stock vs ledger, balanced journals, sale totals/payments, loyalty balances.';

    public function handle(IntegrityChecker $checker): int
    {
        $results = $checker->run();
        $rows = [];
        foreach ($results as $name => $r) {
            $rows[] = [$name, $r['ok'] ? 'OK' : 'FAIL', $r['count'], implode('; ', array_slice($r['sample'], 0, 3))];
        }
        $this->table(['Check', 'Result', 'Problems', 'Examples'], $rows);
        $failed = array_filter($results, fn ($r) => ! $r['ok']);
        if ($failed) {
            Log::warning('erp:integrity found problems', array_map(fn ($r) => $r['sample'], $failed));
            $this->error(count($failed).' check(s) failed. Investigate and correct through the normal audited processes.');

            return self::FAILURE;
        }
        $this->info('All integrity checks passed.');

        return self::SUCCESS;
    }
}
