<?php

namespace Tests\Feature\Security;

use App\Livewire\Auth\Login;
use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Users\Livewire\UserIndex;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_reach_any_erp_page(): void
    {
        foreach (['dashboard', 'products.index', 'inventory.index', 'branches.index', 'users.index', 'transfers.index', 'inventory.movements'] as $route) {
            $this->get(route($route))->assertRedirect(route('login'));
        }
    }

    public function test_cashier_is_blocked_from_administration_pages(): void
    {
        $branch = Branch::factory()->create();
        $this->actingAsRole('cashier', [$branch]);

        $this->get(route('dashboard'))->assertOk();
        foreach (['users.index', 'branches.index', 'categories.index', 'brands.index', 'audit.index', 'inventory.movements', 'transfers.create'] as $route) {
            $this->get(route($route))->assertForbidden();
        }
    }

    public function test_user_cannot_switch_to_an_unassigned_branch(): void
    {
        $mine = Branch::factory()->create();
        $other = Branch::factory()->create();
        $this->actingAsRole('branch-manager', [$mine]);

        $this->post(route('branch.switch'), ['branch_id' => $other->id])->assertForbidden();
        $this->post(route('branch.switch'), ['branch_id' => 0])->assertForbidden();
        $this->post(route('branch.switch'), ['branch_id' => $mine->id])->assertRedirect();
        $this->get(route('branches.show', $other))->assertForbidden();
    }

    public function test_login_works_and_is_rate_limited(): void
    {
        $user = User::factory()->create(['email' => 'staff@example.test']);

        for ($i = 0; $i < 5; $i++) {
            Livewire::test(Login::class)->set('email', 'staff@example.test')->set('password', 'wrong-password')->call('login')->assertHasErrors('email');
        }
        // Sixth attempt is throttled even with the right password.
        Livewire::test(Login::class)->set('email', 'staff@example.test')->set('password', 'Password-123')->call('login')->assertHasErrors('email');
        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.failed']);
    }

    public function test_successful_login(): void
    {
        $user = User::factory()->create(['email' => 'ok@example.test']);
        Livewire::test(Login::class)->set('email', 'ok@example.test')->set('password', 'Password-123')->call('login')->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_inactive_users_cannot_log_in_and_are_logged_out(): void
    {
        $user = User::factory()->inactive()->create(['email' => 'off@example.test']);
        Livewire::test(Login::class)->set('email', 'off@example.test')->set('password', 'Password-123')->call('login')->assertHasErrors('email');
        $this->assertGuest();

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_non_super_admin_cannot_create_admins(): void
    {
        $branch = Branch::factory()->create();
        $this->actingAsRole('admin', [$branch]);

        Livewire::test(UserIndex::class)
            ->call('create')
            ->set('form.name', 'X')->set('form.email', 'x@example.test')->set('form.password', 'Strong-Pass-1')
            ->set('form.role', 'admin')->set('form.branch_ids', [(string) $branch->id])
            ->call('save')
            ->assertHasErrors('form.role');
    }

    public function test_csrf_protection_is_active_on_the_web_group(): void
    {
        // Laravel bypasses CSRF while running tests, so assert the middleware is registered instead.
        $web = app(Kernel::class)->getMiddlewareGroups()['web'];
        // Laravel 13 renamed ValidateCsrfToken to PreventRequestForgery.
        $this->assertContains(PreventRequestForgery::class, $web);
    }

    public function test_security_headers_are_sent(): void
    {
        $this->get(route('login'))
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }

    public function test_output_is_escaped(): void
    {
        $branch = Branch::factory()->create(['name' => '<script>alert(1)</script>']);
        $this->actingAsSuperAdmin();
        $this->get(route('branches.index'))->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
    }
}
