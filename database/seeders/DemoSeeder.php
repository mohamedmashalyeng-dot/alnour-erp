<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Core\Permissions;
use App\Modules\Inventory\Services\InventoryService;
use Illuminate\Database\Seeder;

/**
 * LOCAL DEMO DATA ONLY. Refuses to run in production.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('DemoSeeder is disabled in production.');

            return;
        }
        $this->call([RolesAndPermissionsSeeder::class, ChartOfAccountsSeeder::class]);

        $branches = collect([
            ['code' => 'B01', 'name' => 'الفرع الرئيسي', 'name_en' => 'Main Branch', 'city' => 'الرياض'],
            ['code' => 'B02', 'name' => 'فرع الشمال', 'name_en' => 'North Branch', 'city' => 'الرياض'],
            ['code' => 'B03', 'name' => 'فرع جدة', 'name_en' => 'Jeddah Branch', 'city' => 'جدة'],
        ])->map(fn ($b) => Branch::firstOrCreate(['code' => $b['code']], $b));

        $admin = User::firstOrCreate(['email' => 'admin@example.test'], ['name' => 'Demo Admin', 'password' => 'Demo-Admin-2026', 'locale' => 'ar']);
        $admin->assignRole(Permissions::SUPER_ADMIN_ROLE);
        $admin->branches()->syncWithoutDetaching($branches->pluck('id'));

        $cashier = User::firstOrCreate(['email' => 'cashier@example.test'], ['name' => 'Demo Cashier', 'password' => 'Demo-Cashier-2026', 'locale' => 'ar', 'default_branch_id' => $branches[0]->id]);
        $cashier->syncRoles(['cashier']);
        $cashier->branches()->sync([$branches[0]->id]);

        $frames = Category::firstOrCreate(['code' => 'FRM'], ['name' => 'إطارات طبية', 'name_en' => 'Optical frames']);
        $sun = Category::firstOrCreate(['code' => 'SUN'], ['name' => 'نظارات شمسية', 'name_en' => 'Sunglasses']);
        $brands = collect(['Ray-Ban', 'Oakley', 'Vogue', 'Carrera'])->map(fn ($n) => Brand::firstOrCreate(['name' => $n], ['name_en' => $n]));

        auth()->login($admin);
        $inventory = app(InventoryService::class);
        foreach (range(1, 12) as $i) {
            $product = Product::firstOrCreate(['sku' => sprintf('DEMO-%03d', $i)], [
                'name' => ($i % 2 ? 'إطار طبي ' : 'نظارة شمسية ').$i,
                'name_en' => ($i % 2 ? 'Optical frame ' : 'Sunglasses ').$i,
                'barcode' => '2900000'.str_pad((string) $i, 5, '0', STR_PAD_LEFT).'0',
                'product_type' => $i % 2 ? 'frame' : 'sunglasses',
                'category_id' => $i % 2 ? $frames->id : $sun->id,
                'brand_id' => $brands[$i % 4]->id,
                'model' => 'M'.(1000 + $i),
                'color' => ['أسود', 'ذهبي', 'فضي', 'بني'][$i % 4],
                'size' => '52-18-140',
                'purchase_price' => 15000 + $i * 1000,
                'selling_price' => 35000 + $i * 2500,
            ]);
            if ($product->wasRecentlyCreated) {
                $product->forceFill(['cost_price' => $product->purchase_price])->save();
                foreach ($branches as $b) {
                    $inventory->recordOpeningStock($b->id, [['product_id' => $product->id, 'quantity' => 5 + $i % 4]]);
                    $inventory->setMinStockLevel($b->id, $product->id, 3);
                }
            }
        }
        auth()->logout();
    }
}
