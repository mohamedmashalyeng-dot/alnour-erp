<?php

namespace App\Modules\Imports\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportRow extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['data' => 'array', 'errors' => 'array', 'warnings' => 'array'];
    }

    public function log(): BelongsTo
    {
        return $this->belongsTo(ImportLog::class, 'import_log_id');
    }
}
