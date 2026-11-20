<?php

namespace App\Modules\Website\Support;

use App\Modules\Branches\Models\Branch;
use App\Modules\Core\Services\Settings;
use Illuminate\Support\Collection;

/**
 * Everything the public pages show about the business, read from the ERP settings and branches.
 */
class SiteInfo
{
    private ?Collection $branches = null;

    public function __construct(private Settings $settings) {}

    public function get(string $key): mixed
    {
        return $this->settings->get('website_'.$key);
    }

    public function enabled(): bool
    {
        return (bool) $this->get('enabled');
    }

    public function showPrices(): bool
    {
        return (bool) $this->get('show_prices');
    }

    public function showStock(): bool
    {
        return (bool) $this->get('show_stock');
    }

    public function companyName(): string
    {
        return (string) $this->settings->get('company_name');
    }

    /** Public phone: the website phone setting, else the first branch phone. */
    public function phone(): ?string
    {
        return filled($this->get('phone')) ? (string) $this->get('phone') : $this->branches()->pluck('phone')->filter()->first();
    }

    /** wa.me link (with an optional pre-filled message), or null when no WhatsApp number is set. */
    public function whatsappUrl(?string $text = null): ?string
    {
        $number = self::internationalNumber((string) $this->get('whatsapp'), (string) $this->get('whatsapp_country_code'));
        if ($number === null) {
            return null;
        }

        return 'https://wa.me/'.$number.($text ? '?text='.rawurlencode($text) : '');
    }

    public static function internationalNumber(string $raw, string $countryCode): ?string
    {
        $digits = preg_replace('/\D+/', '', $raw);
        $cc = preg_replace('/\D+/', '', $countryCode);
        if ($digits === '' || strlen($digits) < 7) {
            return null;
        }
        if (str_starts_with($digits, '00')) {
            return substr($digits, 2);
        }
        if (str_starts_with($digits, '0') && $cc !== '') {
            return $cc.substr($digits, 1);
        }

        return $digits;
    }

    /** @return array<string, string> network => url (only the ones that are set) */
    public function socials(): array
    {
        return collect(['facebook', 'instagram', 'tiktok'])
            ->mapWithKeys(fn ($n) => [$n => (string) $this->get($n)])
            ->filter(fn ($url) => str_starts_with($url, 'https://'))
            ->all();
    }

    public function branches(): Collection
    {
        return $this->branches ??= Branch::where('is_active', true)->orderBy('id')
            ->get(['id', 'code', 'name', 'name_en', 'phone', 'address', 'city']);
    }

    public static function mapUrl(Branch $branch): ?string
    {
        $q = trim(implode(' ', array_filter([$branch->address, $branch->city])));

        return $q === '' ? null : 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($q);
    }
}
