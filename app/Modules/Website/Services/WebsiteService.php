<?php

namespace App\Modules\Website\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Services\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Dashboard-side control of the public website: which products are published/featured, and the site texts.
 */
class WebsiteService
{
    /** Setting keys editable from the Website settings page. */
    public const SETTING_KEYS = [
        'website_enabled', 'website_show_prices', 'website_show_stock',
        'website_hero_title', 'website_hero_subtitle', 'website_about', 'website_tagline', 'website_hours',
        'website_phone', 'website_whatsapp', 'website_whatsapp_country_code',
        'website_facebook', 'website_instagram', 'website_tiktok',
    ];

    public function __construct(private AuditLogger $audit, private Settings $settings) {}

    /**
     * Publish or unpublish products on the website.
     *
     * @param  list<int>  $ids
     */
    public function setPublished(array $ids, bool $published): int
    {
        Gate::authorize('website.manage');

        return $this->setFlag($ids, 'show_on_website', $published, 'website.products_published');
    }

    /** @param list<int> $ids */
    public function setFeatured(array $ids, bool $featured): int
    {
        Gate::authorize('website.manage');

        return $this->setFlag($ids, 'website_featured', $featured, 'website.products_featured');
    }

    /** @param array<string, mixed> $values validated values keyed by SETTING_KEYS */
    public function saveSettings(array $values): void
    {
        Gate::authorize('website.manage');
        $values = array_intersect_key($values, array_flip(self::SETTING_KEYS));
        $old = array_intersect_key($this->settings->all(), $values);
        foreach ($values as $key => $value) {
            $this->settings->set($key, $value);
        }
        $this->audit->log('website.settings_updated', null, $old, $values);
    }

    private function setFlag(array $ids, string $column, bool $value, string $event): int
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return 0;
        }

        return DB::transaction(function () use ($ids, $column, $value, $event) {
            $changed = Product::whereIn('id', $ids)->where($column, '!=', $value)->pluck('id')->all();
            if ($changed === []) {
                return 0;
            }
            // Featuring implies publishing; unpublishing removes the "featured" mark.
            $extra = match (true) {
                $column === 'website_featured' && $value => ['show_on_website' => true],
                $column === 'show_on_website' && ! $value => ['website_featured' => false],
                default => [],
            };
            Product::whereIn('id', $changed)->update([$column => $value, ...$extra, 'updated_at' => now()]);
            $this->audit->log($event, null, [], ['product_ids' => $changed, $column => $value]);

            return count($changed);
        });
    }
}
