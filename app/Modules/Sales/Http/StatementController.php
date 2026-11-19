<?php

namespace App\Modules\Sales\Http;

use App\Http\Controllers\Controller;
use App\Modules\Core\Services\Settings;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Services\CustomerLedger;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Suppliers\Services\SupplierLedger;
use Illuminate\Http\Request;

/**
 * Printable account statements (browser print / save as PDF).
 */
class StatementController extends Controller
{
    public function customer(Request $request, Customer $customer, CustomerLedger $ledger, Settings $settings)
    {
        abort_unless($request->user()->can('reports.financial') || $request->user()->can('payments.record'), 403);
        [$from, $to] = $this->range($request);

        return view('documents.statement', [
            'title' => __('Customer statement'),
            'party' => $customer->name,
            'partyInfo' => $customer->phone,
            'statement' => $ledger->statement($customer, $from, $to),
            'debitLabel' => __('Debit'),
            'creditLabel' => __('Credit'),
            'company' => $settings->get('company_name'),
            'from' => $from, 'to' => $to,
        ]);
    }

    public function supplier(Request $request, Supplier $supplier, SupplierLedger $ledger, Settings $settings)
    {
        abort_unless($request->user()->can('reports.financial') || $request->user()->can('supplier-payments.record'), 403);
        [$from, $to] = $this->range($request);

        return view('documents.statement', [
            'title' => __('Supplier statement'),
            'party' => $supplier->name,
            'partyInfo' => $supplier->company_name,
            'statement' => $ledger->statement($supplier, $from, $to),
            'debitLabel' => __('Paid'),
            'creditLabel' => __('Purchases'),
            'company' => $settings->get('company_name'),
            'from' => $from, 'to' => $to,
        ]);
    }

    private function range(Request $request): array
    {
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);

        return [$data['from'] ?? null, $data['to'] ?? null];
    }
}
