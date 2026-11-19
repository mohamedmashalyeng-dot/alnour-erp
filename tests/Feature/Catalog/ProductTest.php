<?php

namespace Tests\Feature\Catalog;

use App\Modules\Branches\Models\Branch;
use App\Modules\Catalog\Livewire\ProductForm;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\CodeGenerator;
use App\Modules\Catalog\Services\ProductService;
use App\Modules\Core\Models\AuditLog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'إطار راي بان',
            'name_en' => 'Ray-Ban frame',
            'sku' => 'RB-5154-BLK',
            'barcode' => '4006381333931',
            'product_type' => 'frame',
            'category_id' => Category::factory()->create()->id,
            'brand_id' => Brand::factory()->create()->id,
            'model' => 'RB5154',
            'color' => 'Black',
            'size' => '51-21-145',
            'frame_type' => 'full_rim',
            'frame_material' => 'acetate',
            'lens_type' => 'plano',
            'gender' => 'unisex',
            'specifications' => ['lens_width' => '51', 'bridge_width' => '21', 'base_curve' => 'ignored-for-frames'],
            'purchase_price' => '250.00',
            'selling_price' => '549.50',
            'description' => 'Classic clubmaster',
            'is_active' => true,
        ], $overrides);
    }

    public function test_admin_can_create_a_product_with_eyewear_fields(): void
    {
        $this->actingAsRole('admin');

        $product = app(ProductService::class)->create($this->payload());

        $this->assertSame('RB-5154-BLK', $product->sku);
        $this->assertSame(25000, $product->purchase_price);
        $this->assertSame(54950, $product->selling_price);
        $this->assertSame(25000, $product->cost_price);
        $this->assertSame('acetate', $product->frame_material->value);
        // Only spec keys valid for the product type are kept.
        $this->assertSame(['lens_width' => '51', 'bridge_width' => '21'], $product->specifications);
        $this->assertDatabaseHas('audit_logs', ['event' => 'product.created', 'auditable_id' => $product->id]);
    }

    public function test_product_can_be_created_through_the_livewire_form(): void
    {
        $this->actingAsRole('admin');
        Storage::fake('public');

        Livewire::test(ProductForm::class)
            ->set('form', $this->payload(['sku' => '', 'barcode' => '']))
            ->set('uploads', [UploadedFile::fake()->image('frame.jpg', 400, 300)])
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $product = Product::firstOrFail();
        $this->assertStringStartsWith('EW-', $product->sku);
        $this->assertTrue(CodeGenerator::isValidEan13($product->barcode), 'Auto barcode must be a valid EAN-13');
        $this->assertCount(1, $product->images);
        Storage::disk('public')->assertExists($product->images->first()->path);
    }

    public function test_duplicate_sku_is_rejected(): void
    {
        $this->actingAsRole('admin');
        app(ProductService::class)->create($this->payload());

        try {
            app(ProductService::class)->create($this->payload(['barcode' => '9780201379624']));
            $this->fail('Duplicate SKU was accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('sku', $e->errors());
        }
        $this->assertSame(1, Product::count());
    }

    public function test_duplicate_barcode_is_rejected(): void
    {
        $this->actingAsRole('admin');
        app(ProductService::class)->create($this->payload());

        try {
            app(ProductService::class)->create($this->payload(['sku' => 'OTHER-SKU']));
            $this->fail('Duplicate barcode was accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('barcode', $e->errors());
        }
        $this->assertSame(1, Product::count());
    }

    public function test_database_unique_constraints_back_up_validation(): void
    {
        $existing = Product::factory()->create();
        $this->expectException(UniqueConstraintViolationException::class);
        Product::factory()->create(['sku' => $existing->sku]);
    }

    public function test_updating_a_product_cannot_take_another_products_barcode(): void
    {
        $this->actingAsRole('admin');
        $a = Product::factory()->create();
        $b = Product::factory()->create();

        $this->expectException(ValidationException::class);
        app(ProductService::class)->update($b, $this->payload(['sku' => $b->sku, 'barcode' => $a->barcode]));
    }

    public function test_svg_upload_is_rejected(): void
    {
        $this->actingAsRole('admin');
        Storage::fake('public');
        $product = Product::factory()->create();
        $svg = UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $this->expectException(ValidationException::class);
        app(ProductService::class)->addImage($product, $svg);
    }

    public function test_cashier_cannot_create_or_edit_products(): void
    {
        $branch = Branch::factory()->create();
        $this->actingAsRole('cashier', [$branch]);
        $product = Product::factory()->create();

        $this->get(route('products.create'))->assertForbidden();
        $this->get(route('products.edit', $product))->assertForbidden();
        // The service layer enforces permissions too (not only the UI).
        $this->expectException(AuthorizationException::class);
        app(ProductService::class)->setActive($product, false);
    }

    public function test_product_pages_and_labels_render(): void
    {
        $this->actingAsRole('admin', [Branch::factory()->create()]);
        $product = Product::factory()->create(['qr_code' => 'QR-123']);

        $this->get(route('products.index', ['search' => $product->sku]))->assertOk()->assertSee($product->sku);
        $this->get(route('products.show', $product))->assertOk()->assertSee('<svg', false);
        $this->get(route('products.labels', ['ids' => (string) $product->id, 'qr' => 1]))->assertOk()->assertSee($product->barcode);
    }

    public function test_search_matches_barcode_sku_and_qr(): void
    {
        $p = Product::factory()->create(['qr_code' => 'QR-XYZ']);
        Product::factory()->count(3)->create();

        $this->assertSame([$p->id], Product::search($p->barcode)->pluck('id')->all());
        $this->assertSame([$p->id], Product::search($p->sku)->pluck('id')->all());
        $this->assertSame([$p->id], Product::search('QR-XYZ')->pluck('id')->all());
    }

    public function test_deactivation_is_audited(): void
    {
        $this->actingAsRole('admin');
        $p = Product::factory()->create();
        app(ProductService::class)->setActive($p, false);

        $this->assertFalse($p->fresh()->is_active);
        $this->assertTrue(AuditLog::where('event', 'product.deactivated')->exists());
    }
}
