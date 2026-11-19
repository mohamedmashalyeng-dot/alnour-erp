<?php

namespace App\Modules\Imports\Livewire;

use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Imports\Models\ImportLog;
use App\Modules\Imports\Services\ProductImportService;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Import wizard: map columns → validate (chunked) → review errors/duplicates → confirm → import (chunked).
 * Chunks advance through wire:poll, so no background worker is needed on shared hosting.
 */
class ImportShow extends Component
{
    use WithPagination;

    #[Locked]
    public int $logId;

    /** @var array<int,string> */
    public array $mapping = [];

    public string $strategy = 'skip';

    /** @var list<string> */
    public array $updateFields = [];

    public ?int $branchId = null;

    public string $filter = 'problems';

    public function mount(ImportLog $import, BranchAccess $access): void
    {
        $this->authorize('imports.manage');
        $this->logId = $import->id;
        foreach ((array) $import->headers as $i => $h) {
            $this->mapping[$i] = $import->column_mapping[$i] ?? '';
        }
        $this->strategy = $import->duplicate_strategy;
        $this->updateFields = $import->options['update_fields'] ?? [];
        $this->branchId = $import->branch_id ?? $access->currentOperational();
    }

    private function log(): ImportLog
    {
        return ImportLog::findOrFail($this->logId);
    }

    public function validateImport(ProductImportService $s): void
    {
        $s->configure($this->log(), $this->mapping, $this->strategy, $this->updateFields, $this->branchId);
    }

    public function step(ProductImportService $s): void
    {
        $log = $this->log();
        if ($log->isActive()) {
            $s->step($log);
        }
    }

    public function confirmImport(ProductImportService $s): void
    {
        $s->confirm($this->log());
    }

    public function remap(): void
    {
        $this->authorize('imports.manage');
        $log = $this->log();
        if ($log->status === 'validated') {
            $log->update(['status' => 'mapping']);
        }
    }

    public function cancel(ProductImportService $s): void
    {
        $s->cancel($this->log());
    }

    public function render(BranchAccess $access)
    {
        $log = $this->log();
        $rows = $log->rows()
            ->when($this->filter === 'problems', fn ($q) => $q->where(fn ($w) => $w->whereNotNull('errors')->orWhereNotNull('warnings')))
            ->when($this->filter === 'all', fn ($q) => $q)
            ->orderBy('row_number')->paginate(25);
        $preview = $log->status === 'mapping' ? $log->rows()->orderBy('row_number')->limit(5)->get() : collect();
        $fields = array_keys(ProductImportService::FIELDS);
        foreach ($access->allowedBranches() as $b) {
            $fields[] = 'stock:'.$b->code;
        }

        return view('livewire.imports.show', [
            'log' => $log, 'rows' => $rows, 'preview' => $preview, 'fields' => $fields,
            'updatable' => ProductImportService::UPDATABLE, 'branches' => $access->allowedBranches(),
        ])->title(__('Import').' #'.$log->id);
    }
}
