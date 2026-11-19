<?php

namespace App\Console\Commands;

use App\Modules\Core\Services\BackupService;
use Illuminate\Console\Command;
use Throwable;

class ErpRestore extends Command
{
    protected $signature = 'erp:restore {file : Path to an erp-*.ndjson.gz backup} {--verify-only : Only check the checksum and header} {--force : Required; restoring replaces ALL current data}';

    protected $description = 'Verify and restore a backup made by erp:backup (replaces the data of every backed-up table).';

    public function handle(BackupService $backups): int
    {
        $file = (string) $this->argument('file');
        if (! is_file($file) && is_file($backups->directory().'/'.$file)) {
            $file = $backups->directory().'/'.$file;
        }
        try {
            $header = $backups->verify($file);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info("Checksum OK. Backup from {$header['created_at']} ({$header['driver']}), ".count($header['tables']).' tables.');
        if ($this->option('verify-only')) {
            return self::SUCCESS;
        }
        if (! $this->option('force')) {
            $this->error('Restoring REPLACES all current data. Take a fresh backup first, then re-run with --force.');

            return self::FAILURE;
        }
        try {
            $r = $backups->restore($file);
        } catch (Throwable $e) {
            $this->error('Restore failed, nothing was changed: '.$e->getMessage());

            return self::FAILURE;
        }
        foreach ($r['warnings'] as $w) {
            $this->warn($w);
        }
        $this->info(sprintf('Restored %d tables, %d rows. Run "php artisan erp:integrity" next.', count($r['tables']), array_sum($r['tables'])));

        return self::SUCCESS;
    }
}
