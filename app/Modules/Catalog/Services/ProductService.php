<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Enums\FrameMaterial;
use App\Modules\Catalog\Enums\FrameType;
use App\Modules\Catalog\Enums\Gender;
use App\Modules\Catalog\Enums\LensType;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Product write operations. Used by the UI and (later) by the import engine, so all
 * validation lives here rather than in Livewire components.
 */
class ProductService
{
    public function __construct(
        private CodeGenerator $codes,
        private AuditLogger $audit,
    ) {}

    public function rules(?Product $product = null): array
    {
        $id = $product?->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9\-_.\/]+$/', Rule::unique('products', 'sku')->ignore($id)],
            'barcode' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9\-]+$/', Rule::unique('products', 'barcode')->ignore($id)],
            'qr_code' => ['nullable', 'string', 'max:191', Rule::unique('products', 'qr_code')->ignore($id)],
            'product_type' => ['required', Rule::in(ProductType::values())],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'tax_rate_id' => ['nullable', 'integer', 'exists:tax_rates,id'],
            'model' => ['nullable', 'string', 'max:100'],
            'color' => ['nullable', 'string', 'max:64'],
            'size' => ['nullable', 'string', 'max:32'],
            'frame_type' => ['nullable', Rule::in(FrameType::values())],
            'frame_material' => ['nullable', Rule::in(FrameMaterial::values())],
            'lens_type' => ['nullable', Rule::in(LensType::values())],
            'gender' => ['nullable', Rule::in(Gender::values())],
            'specifications' => ['nullable', 'array'],
            'specifications.*' => ['nullable', 'string', 'max:64'],
            'purchase_price' => ['required', 'string', 'max:20', $this->moneyRule()],
            'selling_price' => ['required', 'string', 'max:20', $this->moneyRule()],
            'description' => ['nullable', 'string', 'max:5000'],
            'track_stock' => ['boolean'],
            'is_active' => ['boolean'],
            'warranty_months' => ['nullable', 'integer', 'min:0', 'max:120'],
        ];
    }

    private function moneyRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            if (! Money::isValid((string) $value) || Money::parse((string) $value) < 0) {
                $fail(__('The :attribute must be a valid non-negative amount.', ['attribute' => __(str_replace('_', ' ', $attribute))]));
            }
        };
    }

    /**
     * @param  array<string,mixed>  $input  Prices as decimal strings (e.g. "149.00").
     */
    public function create(array $input): Product
    {
        Gate::authorize('products.create');
        $data = $this->validate($input);

        return $this->persist(function () use ($data) {
            $data['sku'] = $data['sku'] ?: $this->codes->nextSku();
            $data['barcode'] = $data['barcode'] ?: $this->codes->nextInternalBarcode();
            $data['qr_code'] = $data['qr_code'] ?: null;

            $product = new Product($data);
            $product->cost_price = $data['purchase_price'];
            $product->created_by = auth()->id();
            $product->updated_by = auth()->id();
            $product->save();

            $this->audit->created($product, 'product');

            return $product;
        });
    }

    public function update(Product $product, array $input): Product
    {
        Gate::authorize('products.update');
        $data = $this->validate($input, $product);

        return $this->persist(function () use ($product, $data) {
            $original = $product->getAttributes();
            // SKU/barcode may be changed but never blanked.
            $data['sku'] = $data['sku'] ?: $product->sku;
            $data['barcode'] = $data['barcode'] ?: $product->barcode;
            $data['qr_code'] = $data['qr_code'] ?: null;

            $product->fill($data);
            $product->updated_by = auth()->id();
            $product->save();

            if ($product->wasChanged()) {
                $this->audit->updated($product, 'product', $original);
            }

            return $product;
        });
    }

    public function setActive(Product $product, bool $active): void
    {
        Gate::authorize('products.toggle');
        $old = $product->is_active;
        $product->forceFill(['is_active' => $active, 'updated_by' => auth()->id()])->save();
        $this->audit->log('product.'.($active ? 'activated' : 'deactivated'), $product, ['is_active' => $old], ['is_active' => $active]);
    }

    /**
     * Store an uploaded image under a random name. Only raster images are accepted (no SVG -> no stored XSS).
     */
    public function addImage(Product $product, UploadedFile $file): ProductImage
    {
        Gate::authorize('products.update');
        Validator::make(['image' => $file], [
            'image' => ['required', 'file', 'image', 'mimes:'.implode(',', config('erp.uploads.image_mimes')),
                'max:'.config('erp.uploads.image_max_kb'), 'dimensions:max_width=6000,max_height=6000'],
        ])->validate();

        $ext = match ($file->getMimeType()) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
        $path = $file->storeAs("products/{$product->id}", Str::random(40).'.'.$ext, 'public');

        $image = $product->images()->create([
            'disk' => 'public',
            'path' => $path,
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
            'sort_order' => (int) $product->images()->max('sort_order') + 1,
            'is_primary' => ! $product->images()->exists(),
            'uploaded_by' => auth()->id(),
        ]);
        $this->audit->log('product.image_added', $product, [], ['image_id' => $image->id]);

        return $image;
    }

    public function removeImage(ProductImage $image): void
    {
        Gate::authorize('products.update');
        $product = $image->product;
        Storage::disk($image->disk)->delete($image->path);
        $wasPrimary = $image->is_primary;
        $image->delete();
        if ($wasPrimary) {
            $product->images()->first()?->update(['is_primary' => true]);
        }
        $this->audit->log('product.image_removed', $product, ['image_id' => $image->id]);
    }

    /** @return array<string,mixed> */
    private function validate(array $input, ?Product $product = null): array
    {
        foreach (['sku', 'barcode', 'qr_code'] as $code) {
            if (isset($input[$code]) && is_string($input[$code])) {
                $input[$code] = trim($input[$code]);
            }
        }
        $data = Validator::make($input, $this->rules($product))->validate();

        $data['purchase_price'] = Money::parse((string) $data['purchase_price']);
        $data['selling_price'] = Money::parse((string) $data['selling_price']);
        if (array_key_exists('warranty_months', $input)) {
            $data['warranty_months'] = (int) ($data['warranty_months'] ?? 0);
        }

        // Keep only spec keys relevant to the product type, drop empties.
        $allowed = ProductType::from($data['product_type'])->specKeys();
        $specs = array_filter(
            array_intersect_key($data['specifications'] ?? [], array_flip($allowed)),
            fn ($v) => $v !== null && $v !== ''
        );
        $data['specifications'] = $specs ?: null;

        foreach (['sku', 'barcode', 'qr_code', 'category_id', 'brand_id', 'supplier_id', 'tax_rate_id', 'frame_type', 'frame_material', 'lens_type', 'gender'] as $nullable) {
            $data[$nullable] = ($data[$nullable] ?? null) ?: null;
        }

        return $data;
    }

    /**
     * Run in a transaction and turn DB unique-constraint races into validation errors.
     */
    private function persist(\Closure $callback): Product
    {
        try {
            return DB::transaction($callback);
        } catch (UniqueConstraintViolationException $e) {
            $field = str_contains($e->getMessage(), 'barcode') ? 'barcode' : (str_contains($e->getMessage(), 'qr_code') ? 'qr_code' : 'sku');
            throw ValidationException::withMessages([$field => __('validation.unique', ['attribute' => __($field)])]);
        }
    }
}
