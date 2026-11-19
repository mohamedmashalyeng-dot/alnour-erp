<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Imports\Models\ImportLog;
use App\Modules\Imports\Services\ProductImportService;
use Illuminate\Console\Command;

/**
 * Cron fallback for imports (shared hosting has no queue workers): advances every import that is
 * validating or processing, within a time budget, acting as the administrator who started it.
 */
class RunImports extends Command
{
    protected $signature = 'imports:run {--seconds=50 : Time budget per run}';

    protected $description = 'Advance pending spreadsheet imports in chunks';

    public function handle(ProductImportService $service): int
    {
        $deadline = microtime(true) + (int) $this->option('seconds');
        foreach (ImportLog::whereIn('status', ['validating', 'processing'])->orderBy('id')->get() as $log) {
            $user = User::find($log->user_id);
            if (! $user || ! $user->is_active || ! $user->can('imports.manage')) {
                $log->update(['status' => 'failed', 'last_error' => 'The user who started this import can no longer run imports.']);

                continue;
            }
            auth()->setUser($user);
            while (microtime(true) < $deadline && ($log = $service->step($log))->isActive()) {
                // keep stepping
            }
            $this->line("Import #{$log->id}: {$log->status}");
        }

        return self::SUCCESS;
    }
}
