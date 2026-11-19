<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;

class TaxRate extends Model
{
    protected $fillable = ['name', 'name_en', 'rate_bp', 'is_default', 'is_active'];

    protected function casts(): array
    {
        return ['rate_bp' => 'integer', 'is_default' => 'boolean', 'is_active' => 'boolean'];
    }

    public function percentLabel(): string
    {
        return rtrim(rtrim(number_format($this->rate_bp / 100, 2, '.', ''), '0'), '.').'%';
    }

    public static function defaultRate(): ?self
    {
        return static::query()->where('is_active', true)->where('is_default', true)->first();
    }
}
