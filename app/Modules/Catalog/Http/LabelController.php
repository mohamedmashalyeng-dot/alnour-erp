<?php

namespace App\Modules\Catalog\Http;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\CodeGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Printable barcode/QR label sheet. Uses the browser's print dialog (works with any label printer).
 */
class LabelController extends Controller
{
    public function __invoke(Request $request, CodeGenerator $codes)
    {
        Gate::authorize('products.print-labels');

        $data = $request->validate([
            'ids' => ['required', 'string', 'max:2000', 'regex:/^\d+(,\d+)*$/'],
            'copies' => ['nullable', 'integer', 'min:1', 'max:50'],
            'qr' => ['nullable', 'boolean'],
        ]);
        $ids = array_slice(array_unique(array_map('intval', explode(',', $data['ids']))), 0, 200);
        $copies = (int) ($data['copies'] ?? 1);
        $withQr = (bool) ($data['qr'] ?? false);

        $labels = [];
        foreach (Product::whereIn('id', $ids)->orderBy('name')->get() as $product) {
            $label = [
                'product' => $product,
                'barcode' => $codes->barcodeSvg($product->barcode, 34, 1.3),
                'qr' => $withQr ? $codes->qrSvg($codes->qrPayload($product), 2) : null,
            ];
            for ($i = 0; $i < $copies; $i++) {
                $labels[] = $label;
            }
        }

        return view('catalog.labels', compact('labels', 'copies', 'withQr', 'ids'));
    }
}
