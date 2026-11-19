<?php

namespace App\Console\Commands;

use App\Modules\Core\Services\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class ErpBackup extends Command
{
    protected $signature = 'erp:backup {--files : Also archive private uploads (attachments, prescriptions)} {--keep=14 : Number of backups to keep}';

    protected $description = 'Create a verified, portable database backup in storage/app/backups (works without mysqldump).';

    public function handle(BackupService $backups): int
    {
        try {
            $r = $backups->backup(withFiles: (bool) $this->option('files'));
        } catch (Throwable $e) {
            Log::error('erp:backup failed', ['error' => $e->getMessage()]);
            $this->error('Backup failed: '.$e->getMessage());

            return self::FAILURE;
        }
        $deleted = $backups->prune(max(1, (int) $this->option('keep')));
        $this->info('Backup written: '.$r['path']);
        $this->line('SHA-256: '.$r['checksum']);
        $this->line(sprintf('%d tables, %d rows, %s KB', count($r['tables']), array_sum($r['tables']), number_format($r['bytes'] / 1024, 1)));
        if (isset($r['files'])) {
            $this->line('Files archive: '.$r['files']);
        }
        if ($deleted) {
            $this->line("Pruned $deleted old backup(s).");
        }
        Log::info('erp:backup ok', ['path' => basename($r['path']), 'rows' => array_sum($r['tables'])]);

        return self::SUCCESS;
    }
}
