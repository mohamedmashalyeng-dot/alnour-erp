<?php

namespace App\Modules\Reports\Http;

use App\Http\Controllers\Controller;
use App\Modules\Branches\Models\Branch;
use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Core\Services\Settings;
use App\Modules\Reports\Services\ReportEngine;
use App\Modules\Reports\Services\ReportExporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ReportExportController extends Controller
{
    public function __invoke(Request $request, ReportEngine $engine, ReportExporter $exporter, BranchAccess $access, Settings $settings)
    {
        Gate::authorize('reports.advanced');
        $d = $request->validate([
            'report' => ['required', Rule::in(array_keys(ReportEngine::REPORTS))],
            'format' => ['required', Rule::in(['csv', 'xlsx', 'pdf'])],
            'branch' => ['nullable', 'integer', 'min:0'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
            'group' => ['nullable', Rule::in(['day', 'week', 'month', 'year'])],
            'compare' => ['nullable', 'boolean'],
            'fromB' => ['nullable', 'date_format:Y-m-d'],
            'toB' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $branch = (int) ($d['branch'] ?? 0);
        if ($branch) {
            $access->authorize($branch);
            $ids = [$branch];
        } else {
            abort_unless($request->user()->canAccessAllBranches(), 403);
            $ids = $access->allowedIds();
        }
        $options = ['group' => $d['group'] ?? 'day'];
        $data = ! empty($d['compare']) && isset(ReportEngine::COMPARABLE[$d['report']]) && ! empty($d['fromB']) && ! empty($d['toB'])
            ? $engine->compare($d['report'], $ids, [$d['fromB'], $d['toB']], [$d['from'], $d['to']], $options)
            : $engine->run($d['report'], $ids, $d['from'], $d['to'], $options);

        $name = $d['report'];

        return match ($d['format']) {
            'csv' => $exporter->csv($data, $name),
            'xlsx' => $exporter->xlsx($data, $name),
            'pdf' => $exporter->pdf($data, $name, [
                'company' => $settings->get('company_name'),
                'branch' => $branch ? Branch::find($branch)?->displayName() : __('All branches'),
                'range' => $d['from'].' → '.$d['to'],
            ]),
        };
    }
}
