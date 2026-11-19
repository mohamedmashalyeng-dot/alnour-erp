<?php

namespace App\Modules\Imports\Livewire;

use App\Modules\Imports\Models\ImportLog;
use App\Modules\Imports\Services\GoogleSheetsSource;
use App\Modules\Imports\Services\ProductImportService;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/** Administrator-only import dashboard: new import (file, Google Sheet, link) + history. */
#[Title('Data import')]
class ImportIndex extends Component
{
    use WithFileUploads, WithPagination;

    public $file;

    public string $source = 'file';

    public string $url = '';

    public function mount(): void
    {
        $this->authorize('imports.manage');
    }

    public function start(ProductImportService $service)
    {
        $this->authorize('imports.manage');
        if ($this->source === 'file') {
            $this->validate(['file' => ['required', 'file', 'max:'.config('erp.imports.max_file_kb')]]);
            $log = $service->fromUpload($this->file);
        } else {
            $this->validate(['url' => ['required', 'url:https', 'max:500']]);
            $log = $this->source === 'google' ? $service->fromGoogleSheet($this->url) : $service->fromUrl($this->url);
        }

        return $this->redirectRoute('imports.show', $log);
    }

    public function render()
    {
        return view('livewire.imports.index', [
            'logs' => ImportLog::with('user:id,name')->latest('id')->paginate(20),
            'googleAuth' => app(GoogleSheetsSource::class)->hasServiceAccount(),
        ]);
    }
}
