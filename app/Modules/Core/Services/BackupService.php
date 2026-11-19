<?php

namespace App\Modules\Core\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use ZipArchive;

/**
 * Portable logical backups that work on shared hosting (no mysqldump / exec needed).
 *
 * Format: gzip-compressed NDJSON.
 *   line 1      {"format":"erp-backup","version":1,"created_at":…,"driver":…,"migrations":[…],"tables":[…]}
 *   per table   {"table":"x","columns":[…]} then one JSON array per row, then {"end":"x","rows":N}
 * A "<file>.sha256" sidecar holds the SHA-256 of the .gz file; restore refuses a file that does not match.
 *
 * Encrypted columns (employees national ID, prescription notes…) stay encrypted inside the backup, so a
 * restore needs the same APP_KEY. Keep a copy of APP_KEY somewhere safe and separate from the backups.
 */
class BackupService
{
    public const FORMAT = 'erp-backup';

    public const VERSION = 1;

    /** Ephemeral tables that are never backed up or restored. */
    public const SKIP = ['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens'];

    public function directory(): string
    {
        $dir = storage_path('app/backups');
        if (! is_dir($dir)) {
            mkdir($dir, 0750, true);
        }

        return $dir;
    }

    /** @return list<string> */
    public function tables(): array
    {
        $schema = DB::getDriverName() === 'mysql' ? DB::connection()->getDatabaseName() : null;
        $names = array_column(Schema::getTables($schema), 'name');
        $names = array_filter($names, fn ($t) => ! in_array($t, self::SKIP, true) && ! str_starts_with($t, 'sqlite_'));
        sort($names);

        return array_values($names);
    }

    /**
     * @return array{path:string, checksum:string, tables:array<string,int>, bytes:int, files?:string}
     */
    public function backup(?string $path = null, bool $withFiles = false): array
    {
        $path ??= $this->directory().'/erp-'.now()->format('Ymd-His').'.ndjson.gz';
        $gz = gzopen($path, 'wb6');
        if (! $gz) {
            throw new RuntimeException("Cannot write $path");
        }
        $tables = $this->tables();
        $this->line($gz, [
            'format' => self::FORMAT, 'version' => self::VERSION, 'created_at' => now()->toIso8601String(),
            'app' => config('app.name'), 'driver' => DB::getDriverName(),
            'migrations' => DB::table('migrations')->orderBy('id')->pluck('migration')->all(),
            'tables' => $tables,
        ]);
        $counts = [];
        foreach ($tables as $table) {
            $columns = Schema::getColumnListing($table);
            $this->line($gz, ['table' => $table, 'columns' => $columns]);
            $order = in_array('id', $columns, true) ? 'id' : $columns[0];
            $n = 0;
            // Stream in chunks so large tables never load into memory.
            foreach (DB::table($table)->orderBy($order)->lazy(1000) as $row) {
                $row = (array) $row;
                $this->line($gz, array_map(fn ($c) => $row[$c] ?? null, $columns));
                $n++;
            }
            $this->line($gz, ['end' => $table, 'rows' => $n]);
            $counts[$table] = $n;
        }
        gzclose($gz);

        $checksum = hash_file('sha256', $path);
        file_put_contents($path.'.sha256', $checksum.'  '.basename($path)."\n");
        $result = ['path' => $path, 'checksum' => $checksum, 'tables' => $counts, 'bytes' => filesize($path)];

        if ($withFiles) {
            $result['files'] = $this->backupFiles(preg_replace('/\.ndjson\.gz$/', '', $path).'-files.zip');
        }

        return $result;
    }

    /** Private uploads (attachments, prescriptions, import files) — never public/. */
    public function backupFiles(string $zipPath): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The zip PHP extension is required for file backups.');
        }
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Cannot write $zipPath");
        }
        foreach ([storage_path('app/private') => 'private', storage_path('app/public') => 'public'] as $root => $prefix) {
            if (! is_dir($root)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                if (str_starts_with($rel, 'backups/')) {
                    continue;
                }
                $zip->addFile($file->getPathname(), $prefix.'/'.$rel);
            }
        }
        $zip->close();
        file_put_contents($zipPath.'.sha256', hash_file('sha256', $zipPath).'  '.basename($zipPath)."\n");

        return $zipPath;
    }

    public function verify(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Backup not found: $path");
        }
        $sidecar = $path.'.sha256';
        if (! is_file($sidecar)) {
            throw new RuntimeException('Checksum file (.sha256) is missing; refusing to restore an unverified backup.');
        }
        $expected = strtok(trim((string) file_get_contents($sidecar)), ' ');
        if (! hash_equals((string) $expected, hash_file('sha256', $path))) {
            throw new RuntimeException('Checksum mismatch: the backup file is damaged or was modified.');
        }
        $gz = gzopen($path, 'rb');
        $header = json_decode((string) gzgets($gz, 10_000_000), true);
        gzclose($gz);
        if (($header['format'] ?? null) !== self::FORMAT || ($header['version'] ?? 0) > self::VERSION) {
            throw new RuntimeException('Not an ERP backup, or made by a newer version.');
        }

        return $header;
    }

    /**
     * Replace the data of every table in the backup. Runs migrations-compatibility checks first.
     *
     * @return array{tables:array<string,int>, warnings:list<string>}
     */
    public function restore(string $path): array
    {
        $header = $this->verify($path);
        $current = DB::table('migrations')->pluck('migration')->all();
        $missing = array_diff($header['migrations'], $current);
        if ($missing) {
            throw new RuntimeException('The backup was made with a newer schema. Run "php artisan migrate" first. Missing: '.implode(', ', array_slice($missing, 0, 5)));
        }
        $warnings = [];
        if ($extra = array_diff($current, $header['migrations'])) {
            $warnings[] = count($extra).' newer migration(s) exist; new columns get their default values.';
        }
        $existing = array_flip($this->tables());

        $counts = [];
        Schema::disableForeignKeyConstraints();
        try {
            DB::transaction(function () use ($path, $header, $existing, &$counts, &$warnings) {
                if (DB::getDriverName() === 'sqlite') {
                    // PRAGMA foreign_keys cannot change inside a transaction; defer the checks to commit instead.
                    DB::statement('PRAGMA defer_foreign_keys = ON');
                }
                // Empty every table first: ON DELETE CASCADE must never remove rows that were already restored.
                foreach ($header['tables'] as $t) {
                    if ($t !== 'migrations' && isset($existing[$t])) {
                        DB::table($t)->delete();
                    }
                }
                $gz = gzopen($path, 'rb');
                gzgets($gz, 10_000_000); // header
                $table = null;
                $columns = $keep = [];
                $batch = [];
                $flush = function () use (&$batch, &$table) {
                    if ($batch && $table) {
                        DB::table($table)->insert($batch);
                    }
                    $batch = [];
                };
                while (($line = gzgets($gz, 50_000_000)) !== false) {
                    $data = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                    if (isset($data['table'], $data['columns']) && ! array_is_list($data)) {
                        $table = $data['table'];
                        if ($table === 'migrations' || ! isset($existing[$table])) {
                            if ($table !== 'migrations') {
                                $warnings[] = "Table $table no longer exists; skipped.";
                            }
                            $table = null;

                            continue;
                        }
                        $columns = $data['columns'];
                        $current = array_flip(Schema::getColumnListing($table));
                        $keep = array_filter($columns, fn ($c) => isset($current[$c]));
                        if (count($keep) !== count($columns)) {
                            $warnings[] = "Table $table: dropped column(s) ".implode(', ', array_diff($columns, $keep)).'.';
                        }
                        $counts[$table] = 0;

                        continue;
                    }
                    if (isset($data['end']) && ! array_is_list($data)) {
                        $flush();
                        if ($table && $counts[$table] !== (int) $data['rows']) {
                            throw new RuntimeException("Row count mismatch in $table.");
                        }
                        $table = null;

                        continue;
                    }
                    if ($table) {
                        $row = array_combine($columns, $data);
                        $batch[] = array_intersect_key($row, array_flip($keep));
                        $counts[$table]++;
                        if (count($batch) >= 500) {
                            $flush();
                        }
                    }
                }
                gzclose($gz);
            });
        } finally {
            Schema::enableForeignKeyConstraints();
        }
        $this->resetSequences(array_keys($counts));

        return ['tables' => $counts, 'warnings' => $warnings];
    }

    /** Delete backups beyond the newest $keep (and their sidecars). */
    public function prune(int $keep): int
    {
        $files = glob($this->directory().'/erp-*.ndjson.gz') ?: [];
        rsort($files);
        $deleted = 0;
        foreach (array_slice($files, $keep) as $f) {
            foreach ([$f, $f.'.sha256', preg_replace('/\.ndjson\.gz$/', '', $f).'-files.zip', preg_replace('/\.ndjson\.gz$/', '', $f).'-files.zip.sha256'] as $x) {
                if (is_file($x)) {
                    unlink($x);
                }
            }
            $deleted++;
        }

        return $deleted;
    }

    /** PostgreSQL-style sequences are not used; MySQL AUTO_INCREMENT follows MAX(id) automatically after inserts. */
    private function resetSequences(array $tables): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        foreach ($tables as $t) {
            if (Schema::hasColumn($t, 'id')) {
                $next = (int) DB::table($t)->max('id') + 1;
                DB::statement("ALTER TABLE `$t` AUTO_INCREMENT = $next");
            }
        }
    }

    private function line($gz, array $data): void
    {
        gzwrite($gz, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)."\n");
    }
}
