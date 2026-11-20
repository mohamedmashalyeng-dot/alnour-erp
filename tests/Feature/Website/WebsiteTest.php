<?php

namespace Tests\Feature\Website;

use App\Modules\Branches\Models\Branch;
use App\Modules\Catalog\Models\Product;
use App\Modules\Core\Services\Settings;
use App\Modules\Website\Enums\InquiryStatus;
use App\Modules\Website\Http\StorefrontController;
use App\Modules\Website\Livewire\InquiryIndex;
use App\Modules\Website\Livewire\WebsiteProducts;
use App\Modules\Website\Models\WebsiteInquiry;
use App\Modules\Website\Services\WebsiteService;
use App\Modules\Website\Support\SiteInfo;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WebsiteTest extends TestCase
{
    use RefreshDatabase;

    private function published(array $attrs = []): Product
    {
        $p = Product::factory()->create($attrs);
        $p->forceFill(['show_on_website' => true])->save();

        return $p;
    }

    private function asGuest(): void
    {
        auth()->logout();
        $this->app['auth']->forgetGuards();
    }

    public function test_only_published_active_products_are_public(): void
    {
        $shown = $this->published(['name' => 'إطار منشور']);
        $hidden = Product::factory()->create(['name' => 'إطار مخفي']);
        $inactive = $this->published(['name' => 'إطار موقوف', 'is_active' => false]);

        $this->get(route('shop.home'))->assertOk();
        $this->get(route('shop.products'))->assertOk()
            ->assertSee('إطار منشور')->assertDontSee('إطار مخفي')->assertDontSee('إطار موقوف');

        $this->get(StorefrontController::url($shown))->assertOk()->assertSee('إطار منشور');
        $this->get(route('shop.product', ['product' => $hidden->id]))->assertNotFound();
        $this->get(route('shop.product', ['product' => $inactive->id]))->assertNotFound();
        $this->get(route('shop.sitemap'))->assertOk()->assertSee(StorefrontController::url($shown), false);
    }

    public function test_product_page_never_reveals_cost_and_redirects_to_canonical_slug(): void
    {
        $p = $this->published(['name_en' => 'Aviator Gold', 'purchase_price' => 12345, 'selling_price' => 99900]);

        $this->get(route('shop.product', ['product' => $p->id]))->assertRedirect(StorefrontController::url($p));
        $this->get(StorefrontController::url($p))->assertOk()
            ->assertSee(money(99900))->assertDontSee(money(12345));
    }

    public function test_filters_and_stock_availability(): void
    {
        $branch = Branch::factory()->create();
        $inStock = $this->published(['name' => 'متوفر', 'selling_price' => 50000]);
        $this->published(['name' => 'نافد', 'selling_price' => 150000]);
        $this->stock($branch, $inStock, 3);
        $this->asGuest();

        $this->get(route('shop.products', ['available' => 1]))->assertOk()->assertSee('متوفر')->assertDontSee('نافد');
        $this->get(route('shop.products', ['min' => '1000']))->assertOk()->assertSee('نافد')->assertDontSee('>متوفر<', false);
        $this->get(route('shop.products', ['type' => 'not-a-type']))->assertSessionHasErrors('type');
    }

    public function test_visitor_can_send_a_request_that_reaches_the_dashboard(): void
    {
        $branch = Branch::factory()->create();
        $p = $this->published();

        $this->post(route('shop.inquiry'), [
            'type' => 'product', 'name' => 'أحمد', 'phone' => '01012345678', 'product_id' => $p->id,
            'branch_id' => $branch->id, 'message' => 'هل متوفر بلون آخر؟',
        ])->assertRedirect(StorefrontController::url($p).'#inquiry')->assertSessionHas('inquiry_sent');

        $inq = WebsiteInquiry::sole();
        $this->assertSame(InquiryStatus::New, $inq->status);
        $this->assertSame('هل متوفر بلون آخر؟', $inq->message);
        $this->assertNotSame('هل متوفر بلون آخر؟', \DB::table('website_inquiries')->value('message'), 'message is encrypted at rest');

        $user = $this->actingAsRole('cashier', [$branch]);
        $this->get(route('dashboard'))->assertOk()->assertSee(trans_choice('{1} One new request from the website|[2,*] :count new requests from the website', 1));
        Livewire::test(InquiryIndex::class)->assertSee('أحمد')->call('move', $inq->id, 'done');
        $this->assertSame(InquiryStatus::Done, $inq->fresh()->status);
        $this->assertSame($user->id, $inq->fresh()->handled_by);
        $this->assertDatabaseHas('audit_logs', ['event' => 'website.inquiry_status']);
    }

    public function test_request_validation_honeypot_and_unpublished_products(): void
    {
        $hidden = Product::factory()->create();

        $this->post(route('shop.inquiry'), ['type' => 'exam', 'name' => 'x', 'phone' => 'abc'])->assertSessionHasErrors(['name', 'phone']);
        $this->post(route('shop.inquiry'), ['type' => 'product', 'name' => 'سارة', 'phone' => '01012345678', 'product_id' => $hidden->id])
            ->assertSessionHasErrors('product_id');
        $this->post(route('shop.inquiry'), ['type' => 'exam', 'name' => 'Bot', 'phone' => '01012345678', 'website' => 'http://spam'])
            ->assertRedirect(route('shop.home'));
        $this->assertSame(0, WebsiteInquiry::count());
    }

    public function test_staff_only_see_requests_for_their_branches(): void
    {
        $mine = Branch::factory()->create();
        $other = Branch::factory()->create();
        foreach ([[$mine, 'لفرعي'], [$other, 'لفرع آخر'], [null, 'بدون فرع']] as [$b, $name]) {
            $this->post(route('shop.inquiry'), ['type' => 'exam', 'name' => $name, 'phone' => '01012345678', 'branch_id' => $b?->id]);
        }

        $this->actingAsRole('cashier', [$mine]);
        $foreign = WebsiteInquiry::where('branch_id', $other->id)->sole();
        Livewire::test(InquiryIndex::class)->assertSee('لفرعي')->assertSee('بدون فرع')->assertDontSee('لفرع آخر');
        Livewire::test(InquiryIndex::class)->call('move', $foreign->id, 'done')->assertNotFound();
        $this->assertSame(InquiryStatus::New, $foreign->fresh()->status);
    }

    public function test_publishing_needs_permission_and_is_audited(): void
    {
        $p = Product::factory()->create();
        $branch = Branch::factory()->create();

        $this->actingAsRole('cashier', [$branch]);
        $this->get(route('website.products'))->assertForbidden();
        try {
            app(WebsiteService::class)->setPublished([$p->id], true);
            $this->fail('cashier must not publish');
        } catch (AuthorizationException) {
        }

        $this->actingAsRole('branch-manager', [$branch]);
        Livewire::test(WebsiteProducts::class)->call('toggleFeatured', $p->id);
        $p->refresh();
        $this->assertTrue($p->show_on_website, 'featuring publishes the product');
        $this->assertTrue($p->website_featured);
        Livewire::test(WebsiteProducts::class)->call('togglePublished', $p->id);
        $this->assertFalse($p->fresh()->website_featured, 'unpublishing clears featured');
        $this->assertDatabaseHas('audit_logs', ['event' => 'website.products_published']);
    }

    public function test_disabled_website_is_hidden_from_visitors_but_previewable_by_staff(): void
    {
        app(Settings::class)->set('website_enabled', false);
        $this->get(route('shop.home'))->assertNotFound();

        $this->actingAsSuperAdmin();
        $this->get(route('shop.home'))->assertOk();
    }

    public function test_whatsapp_numbers_are_normalised(): void
    {
        $this->assertSame('201020141644', SiteInfo::internationalNumber('0102 014 1644', '20'));
        $this->assertSame('966501234567', SiteInfo::internationalNumber('+966 50 123 4567', '20'));
        $this->assertSame('201020141644', SiteInfo::internationalNumber('00201020141644', '20'));
        $this->assertNull(SiteInfo::internationalNumber('', '20'));
    }
}
