<?php

namespace App\Modules\Website\Http;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Enums\FrameType;
use App\Modules\Catalog\Enums\Gender;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Catalog\Models\Product;
use App\Modules\Website\Enums\InquiryType;
use App\Modules\Website\Services\InquiryService;
use App\Modules\Website\Services\WebsiteCatalog;
use App\Modules\Website\Support\SiteInfo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Public website pages. Read-only except for the inquiry form; no login, no prices from the client.
 */
class StorefrontController extends Controller
{
    public function __construct(private WebsiteCatalog $catalog, private SiteInfo $site) {}

    public function home(): View
    {
        $this->ensureEnabled();
        $featured = $this->catalog->featured(8);

        return view('storefront.home', [
            'featured' => $featured,
            'stock' => $this->catalog->stock($featured->pluck('id')->all()),
            'facets' => $this->catalog->facets(),
        ]);
    }

    public function products(Request $request): View
    {
        $this->ensureEnabled();
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'integer'],
            'brand' => ['nullable', 'integer'],
            'type' => ['nullable', Rule::enum(ProductType::class)],
            'gender' => ['nullable', Rule::enum(Gender::class)],
            'frame' => ['nullable', Rule::enum(FrameType::class)],
            'min' => ['nullable', 'string', 'max:20'],
            'max' => ['nullable', 'string', 'max:20'],
            'available' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(WebsiteCatalog::SORTS)],
        ]);
        $products = $this->catalog->search($filters);

        return view('storefront.products', [
            'products' => $products,
            'filters' => $filters,
            'stock' => $this->catalog->stock(collect($products->items())->pluck('id')->all()),
            'facets' => $this->catalog->facets(),
        ]);
    }

    public function product(int $product, ?string $slug = null): View|RedirectResponse
    {
        $this->ensureEnabled();
        $product = $this->catalog->find($product);
        if ($slug !== self::slug($product)) {
            return redirect()->to(self::url($product), 301);
        }
        $related = $this->catalog->related($product);

        return view('storefront.product', [
            'product' => $product,
            'related' => $related,
            'stock' => $this->catalog->stock([$product->id, ...$related->pluck('id')->all()]),
        ]);
    }

    public function inquiry(Request $request, InquiryService $inquiries): RedirectResponse
    {
        $this->ensureEnabled();
        // Honeypot: real visitors never fill the hidden "website" field.
        if (filled($request->input('website'))) {
            return redirect()->route('shop.home')->with('inquiry_sent', true);
        }

        $data = $request->validate([
            'type' => ['required', Rule::enum(InquiryType::class)],
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'phone' => ['required', 'string', 'regex:/^\+?[0-9 \-]{7,20}$/'],
            'message' => ['nullable', 'string', 'max:1000'],
            'preferred_date' => ['nullable', 'date', 'after_or_equal:today', 'before:+6 months'],
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->where('is_active', true)->where('show_on_website', true)],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('is_active', true)],
        ], [], [
            'name' => __('Name'), 'phone' => __('Phone'), 'message' => __('Message'), 'preferred_date' => __('Preferred date'),
        ]);

        $inquiries->submit($data, $request->ip());

        // Never redirect to the Referer (could be another site): go back to our own page.
        $target = isset($data['product_id']) ? self::url($this->catalog->find((int) $data['product_id'])) : route('shop.home');

        return redirect()->to($target.'#inquiry')->with('inquiry_sent', true);
    }

    public function sitemap(): Response
    {
        $this->ensureEnabled();
        $urls = collect([route('shop.home'), route('shop.products')]);
        $this->catalog->base()->reorder()->select(['id', 'name', 'name_en', 'sku', 'updated_at'])->setEagerLoads([])
            ->orderBy('id')->chunk(500, function ($chunk) use ($urls) {
                foreach ($chunk as $p) {
                    $urls->push(self::url($p));
                }
            });

        return response()->view('storefront.sitemap', ['urls' => $urls], 200)->header('Content-Type', 'application/xml');
    }

    public static function slug(Product $product): string
    {
        return Str::slug($product->name_en ?: $product->sku) ?: 'item';
    }

    public static function url(Product $product): string
    {
        return route('shop.product', ['product' => $product->id, 'slug' => self::slug($product)]);
    }

    /** A disabled site is hidden from visitors; staff who manage it can still preview it. */
    private function ensureEnabled(): void
    {
        if (! $this->site->enabled() && ! auth()->user()?->can('website.manage')) {
            abort(404);
        }
    }
}
