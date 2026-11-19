<?php

namespace App\Modules\Sales\Http;

use App\Http\Controllers\Controller;
use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Catalog\Services\CodeGenerator;
use App\Modules\Core\Services\PdfRenderer;
use App\Modules\Core\Services\Settings;
use App\Modules\Payments\Models\Payment;
use App\Modules\Sales\Enums\SaleStatus;
use App\Modules\Sales\Models\Sale;
use Illuminate\Support\Facades\Gate;

class SaleDocumentController extends Controller
{
    public function __construct(private BranchAccess $branches, private Settings $settings) {}

    /** 80mm thermal receipt (browser print). */
    public function receipt(Sale $sale)
    {
        return view('documents.sale-receipt', $this->data($sale));
    }

    /** A4 invoice (browser print). */
    public function invoice(Sale $sale)
    {
        return view('documents.sale-invoice', $this->data($sale) + ['pdf' => false]);
    }

    public function pdf(Sale $sale, PdfRenderer $pdf)
    {
        $html = view('documents.sale-invoice', $this->data($sale) + ['pdf' => true])->render();

        return $pdf->download($html, $sale->invoice_number.'.pdf');
    }

    private function data(Sale $sale): array
    {
        Gate::authorize('sales.view');
        $this->branches->authorize($sale->branch_id);
        abort_if($sale->status === SaleStatus::Draft, 404);

        $sale->load(['items', 'branch', 'customer', 'cashier:id,name']);
        $payments = Payment::query()
            ->whereHas('allocations', fn ($q) => $q->where('allocatable_type', 'sale')->where('allocatable_id', $sale->id))
            ->with(['allocations' => fn ($q) => $q->where('allocatable_type', 'sale')->where('allocatable_id', $sale->id)])
            ->orderBy('paid_at')->get()
            // Show only the portion of each payment applied to this invoice.
            ->each(fn (Payment $p) => $p->setAttribute('applied', (int) $p->allocations->sum('amount')));

        return [
            'sale' => $sale,
            'payments' => $payments,
            'company' => $this->settings->get('company_name'),
            'companyTax' => $this->settings->get('company_tax_number'),
            'footer' => $this->settings->get('invoice_footer'),
            'qr' => app(CodeGenerator::class)->qrSvg($sale->invoice_number, 3),
        ];
    }
}
