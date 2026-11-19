<?php

namespace App\Modules\Catalog\Livewire;

use App\Modules\Catalog\Enums\FrameMaterial;
use App\Modules\Catalog\Enums\FrameType;
use App\Modules\Catalog\Enums\Gender;
use App\Modules\Catalog\Enums\LensType;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Catalog\Services\ProductService;
use App\Modules\Core\Models\TaxRate;
use App\Modules\Suppliers\Models\Supplier;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

class ProductForm extends Component
{
    use WithFileUploads;

    #[Locked]
    public ?int $productId = null;

    public array $form = [];

    /** @var array<int, TemporaryUploadedFile> */
    public array $uploads = [];

    public function mount(?Product $product = null): void
    {
        if ($product?->exists) {
            $this->authorize('products.update');
            $this->productId = $product->id;
            $this->form = [
                'name' => $product->name,
                'name_en' => $product->name_en,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'qr_code' => $product->qr_code,
                'product_type' => $product->product_type->value,
                'category_id' => (string) $product->category_id,
                'brand_id' => (string) $product->brand_id,
                'supplier_id' => (string) $product->supplier_id,
                'tax_rate_id' => (string) $product->tax_rate_id,
                'model' => $product->model,
                'color' => $product->color,
                'size' => $product->size,
                'frame_type' => $product->frame_type?->value,
                'frame_material' => $product->frame_material?->value,
                'lens_type' => $product->lens_type?->value,
                'gender' => $product->gender?->value,
                'specifications' => $product->specifications ?? [],
                'purchase_price' => money_input($product->purchase_price),
                'selling_price' => money_input($product->selling_price),
                'description' => $product->description,
                'track_stock' => $product->track_stock,
                'warranty_months' => (string) $product->warranty_months,
                'is_active' => $product->is_active,
            ];
        } else {
            $this->authorize('products.create');
            $this->form = [
                'name' => '', 'name_en' => '', 'sku' => '', 'barcode' => '', 'qr_code' => '',
                'product_type' => ProductType::Frame->value, 'category_id' => '', 'brand_id' => '', 'supplier_id' => '', 'tax_rate_id' => '',
                'model' => '', 'color' => '', 'size' => '', 'frame_type' => '', 'frame_material' => '', 'lens_type' => '', 'gender' => '',
                'specifications' => [], 'purchase_price' => '0', 'selling_price' => '0', 'description' => '', 'track_stock' => true, 'is_active' => true, 'warranty_months' => '0',
            ];
        }
    }

    public function save(ProductService $service)
    {
        $this->validate([
            'uploads' => ['array', 'max:8'],
            'uploads.*' => ['image', 'mimes:'.implode(',', config('erp.uploads.image_mimes')), 'max:'.config('erp.uploads.image_max_kb')],
        ]);

        try {
            if ($this->productId) {
                $this->authorize('products.update');
                $product = $service->update(Product::findOrFail($this->productId), $this->form);
            } else {
                $this->authorize('products.create');
                $product = $service->create($this->form);
            }
        } catch (ValidationException $e) {
            // Re-key service errors onto the form.* namespace used by the view.
            $errors = [];
            foreach ($e->errors() as $key => $messages) {
                $errors['form.'.$key] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }

        foreach ($this->uploads as $file) {
            $service->addImage($product, $file);
        }
        $this->uploads = [];

        session()->flash('success', __('Product saved successfully.'));

        return $this->redirectRoute('products.show', $product);
    }

    public function removeImage(int $imageId, ProductService $service): void
    {
        $this->authorize('products.update');
        $image = ProductImage::where('product_id', $this->productId)->findOrFail($imageId);
        $service->removeImage($image);
    }

    public function removeUpload(int $index): void
    {
        unset($this->uploads[$index]);
        $this->uploads = array_values($this->uploads);
    }

    public function render()
    {
        $type = ProductType::tryFrom($this->form['product_type'] ?? '') ?? ProductType::Frame;

        return view('livewire.catalog.products.form', [
            'categories' => Category::where('is_active', true)->orderBy('name')->get(['id', 'name', 'name_en']),
            'brands' => Brand::where('is_active', true)->orderBy('name')->get(['id', 'name', 'name_en']),
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'taxRates' => TaxRate::where('is_active', true)->orderBy('rate_bp')->get(),
            'types' => ProductType::options(),
            'frameTypes' => FrameType::options(),
            'materials' => FrameMaterial::options(),
            'lensTypes' => LensType::options(),
            'genders' => Gender::options(),
            'specKeys' => $type->specKeys(),
            'images' => $this->productId ? ProductImage::where('product_id', $this->productId)->orderByDesc('is_primary')->orderBy('sort_order')->get() : collect(),
        ])->title($this->productId ? __('Edit product') : __('New product'));
    }
}
