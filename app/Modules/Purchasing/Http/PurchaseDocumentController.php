<?php

namespace App\Modules\Purchasing\Http;

use App\Http\Controllers\Controller;
use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Core\Services\PdfRenderer;
use App\Modules\Core\Services\Settings;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Support\Facades\Gate;

class PurchaseDocumentController extends Controller
{
    public function pdf(PurchaseOrder $purchase, BranchAccess $branches, Settings $settings, PdfRenderer $pdf)
    {
        Gate::authorize('purchases.view');
        $branches->authorize($purchase->branch_id);
        $purchase->load(['items.product:id,name,sku', 'supplier', 'branch', 'creator:id,name']);

        $html = view('documents.purchase-order', [
            'po' => $purchase,
            'company' => $settings->get('company_name'),
            'companyTax' => $settings->get('company_tax_number'),
        ])->render();

        return $pdf->download($html, $purchase->number.'.pdf');
    }
}
