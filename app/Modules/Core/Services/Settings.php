<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * DB-backed business settings with config defaults. Values are JSON-encoded.
 */
class Settings
{
    private const CACHE_KEY = 'erp.settings';

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();

        if (array_key_exists($key, $all)) {
            return $all[$key];
        }

        return config("erp.defaults.$key", $default);
    }

    public function bool(string $key): bool
    {
        return (bool) $this->get($key);
    }

    public function set(string $key, mixed $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => json_encode($value)]);
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            return Setting::query()->pluck('value', 'key')
                ->map(fn ($v) => json_decode((string) $v, true))
                ->all();
        });
    }
}
