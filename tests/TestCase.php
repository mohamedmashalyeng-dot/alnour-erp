<?php

namespace Tests;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Catalog\Models\Product;
use App\Modules\Core\Permissions;
use App\Modules\Core\Services\Settings;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Inventory\Services\InventoryService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected bool $seedPermissions = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        if ($this->seedPermissions && in_array(RefreshDatabase::class, class_uses_recursive(static::class), true)) {
            $this->seed([RolesAndPermissionsSeeder::class, ChartOfAccountsSeeder::class]);
            // Most tests take cash without a shift; shift enforcement has its own tests.
            app(Settings::class)->set('cash_shift_required', false);
        }
    }

    /**
     * Create a user with a role (or explicit permissions) assigned to the given branches, and log in.
     *
     * @param  list<Branch>  $branches
     * @param  list<string>  $permissions  extra direct permissions
     */
    protected function actingAsRole(string $role, array $branches = [], array $permissions = []): User
    {
        $user = User::factory()->create(['default_branch_id' => $branches[0]->id ?? null]);
        $user->assignRole($role);
        if ($permissions) {
            $user->givePermissionTo($permissions);
        }
        $user->branches()->sync(collect($branches)->pluck('id'));
        $this->actingAs($user);
        app(BranchAccess::class)->flush();

        return $user;
    }

    protected function actingAsSuperAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Permissions::SUPER_ADMIN_ROLE);
        $this->actingAs($user);
        app(BranchAccess::class)->flush();

        return $user;
    }

    /** Put stock in place through the real engine (as opening stock), restoring the current user afterwards. */
    protected function stock(Branch $branch, Product $product, int $qty): void
    {
        $current = auth()->user();
        $this->actingAsSuperAdmin();
        app(InventoryService::class)->recordOpeningStock($branch->id, [['product_id' => $product->id, 'quantity' => $qty]]);
        if ($current) {
            $this->actingAs($current);
        }
        app(BranchAccess::class)->flush();
    }

    protected function qty(Branch $branch, Product $product): int
    {
        return (int) Inventory::where('branch_id', $branch->id)->where('product_id', $product->id)->value('quantity');
    }
}
