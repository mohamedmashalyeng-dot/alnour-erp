<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Private file (receipts, invoices). Stored on the non-public "local" disk and only served
 * through an authorized controller.
 */
class Attachment extends Model
{
    protected $fillable = ['disk', 'path', 'original_name', 'mime', 'size', 'uploaded_by'];

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }
}
