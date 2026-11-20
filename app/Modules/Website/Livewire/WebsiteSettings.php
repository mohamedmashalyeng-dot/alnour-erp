<?php

namespace App\Modules\Website\Livewire;

use App\Modules\Core\Services\Settings;
use App\Modules\Website\Services\WebsiteService;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Website settings')]
class WebsiteSettings extends Component
{
    public array $form = [];

    private const BOOLEANS = ['website_enabled', 'website_show_prices', 'website_show_stock'];

    public function mount(Settings $settings): void
    {
        $this->authorize('website.manage');
        foreach (WebsiteService::SETTING_KEYS as $key) {
            $this->form[$key] = in_array($key, self::BOOLEANS, true) ? $settings->bool($key) : (string) $settings->get($key);
        }
    }

    public function save(WebsiteService $service): void
    {
        $this->authorize('website.manage');
        $url = ['nullable', 'string', 'max:255', 'url:https'];
        $data = $this->validate([
            'form.website_enabled' => ['boolean'],
            'form.website_show_prices' => ['boolean'],
            'form.website_show_stock' => ['boolean'],
            'form.website_hero_title' => ['required', 'string', 'max:150'],
            'form.website_hero_subtitle' => ['nullable', 'string', 'max:255'],
            'form.website_about' => ['nullable', 'string', 'max:2000'],
            'form.website_tagline' => ['nullable', 'string', 'max:100'],
            'form.website_hours' => ['nullable', 'string', 'max:150'],
            'form.website_phone' => ['nullable', 'string', 'regex:/^\+?[0-9 \-]{6,20}$/'],
            'form.website_whatsapp' => ['nullable', 'string', 'regex:/^\+?[0-9 \-]{7,20}$/'],
            'form.website_whatsapp_country_code' => ['required', 'regex:/^[0-9]{1,4}$/'],
            'form.website_facebook' => $url,
            'form.website_instagram' => $url,
            'form.website_tiktok' => $url,
        ], [], [
            'form.website_hero_title' => __('Main headline'),
            'form.website_facebook' => 'Facebook', 'form.website_instagram' => 'Instagram', 'form.website_tiktok' => 'TikTok',
            'form.website_phone' => __('Phone'), 'form.website_whatsapp' => __('WhatsApp number'),
        ])['form'];

        foreach (self::BOOLEANS as $b) {
            $data[$b] = (bool) $data[$b];
        }
        $service->saveSettings($data);
        $this->dispatch('toast', message: __('Saved successfully.'));
    }

    public function render()
    {
        return view('livewire.website.settings');
    }
}
