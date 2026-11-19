<?php

namespace Tests\Feature\Core;

use App\Modules\Branches\Models\Branch;
use App\Modules\Core\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MenuPreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_hide_and_restore_menu_pages(): void
    {
        $user = $this->actingAsSuperAdmin();

        $this->postJson(route('preferences.pages'), ['pages' => ['brands.index', 'audit.index'], 'hidden' => true])
            ->assertOk()->assertJson(['hidden' => ['brands.index', 'audit.index']]);
        $this->assertSame(['brands.index', 'audit.index'], Navigation::hiddenFor($user->fresh()));

        $this->postJson(route('preferences.pages'), ['pages' => ['brands.index'], 'hidden' => false])
            ->assertOk()->assertJson(['hidden' => ['audit.index']]);

        $this->postJson(route('preferences.pages.show-all'))->assertOk()->assertJson(['hidden' => []]);
        $this->assertSame([], Navigation::hiddenFor($user->fresh()));
    }

    public function test_hidden_page_is_flagged_in_the_menu_but_still_opens(): void
    {
        $user = $this->actingAsSuperAdmin();
        $this->postJson(route('preferences.pages'), ['pages' => ['brands.index'], 'hidden' => true])->assertOk();

        $items = collect(Navigation::for($user->fresh()))->flatMap(fn ($g) => $g['items'])->keyBy('route');
        $this->assertTrue($items['brands.index']['hidden']);
        $this->assertFalse($items['products.index']['hidden']);

        // Hiding is cosmetic: the page itself is still reachable.
        $this->get(route('brands.index'))->assertOk();
        $this->get(route('dashboard'))->assertOk()->assertSee(__('Customize menu'));
    }

    public function test_unknown_pages_are_rejected(): void
    {
        $user = $this->actingAsSuperAdmin();

        $this->postJson(route('preferences.pages'), ['pages' => ['evil.route'], 'hidden' => true])->assertUnprocessable();
        $this->postJson(route('preferences.pages'), ['pages' => [], 'hidden' => true])->assertUnprocessable();
        $this->assertSame([], Navigation::hiddenFor($user->fresh()));
    }

    public function test_hiding_a_page_does_not_grant_access_to_it(): void
    {
        $branch = Branch::factory()->create();
        $user = $this->actingAsRole('cashier', [$branch]);

        $this->postJson(route('preferences.pages'), ['pages' => ['users.index'], 'hidden' => false])->assertOk();
        $this->get(route('users.index'))->assertForbidden();

        $routes = collect(Navigation::for($user))->flatMap(fn ($g) => $g['items'])->pluck('route');
        $this->assertNotContains('users.index', $routes);
    }

    public function test_guests_cannot_change_preferences(): void
    {
        $this->postJson(route('preferences.pages'), ['pages' => ['brands.index'], 'hidden' => true])->assertUnauthorized();
    }
}
