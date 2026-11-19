<?php

namespace App\Modules\Imports\Http;

use App\Http\Controllers\Controller;
use App\Modules\Imports\Models\ImportLog;
use App\Modules\Imports\Services\ProductImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class ImportFileController extends Controller
{
    public function report(ImportLog $import)
    {
        Gate::authorize('imports.manage');
        abort_unless($import->error_report_path && Storage::disk('local')->exists($import->error_report_path), 404);

        return Storage::disk('local')->download($import->error_report_path, 'import-'.$import->id.'-report.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function export(Request $request, ProductImportService $service)
    {
        $format = $request->validate(['format' => ['required', 'in:csv,xlsx']])['format'];

        return $service->exportProducts($format);
    }
}
