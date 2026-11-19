<?php

namespace App\Modules\Expenses\Http;

use App\Http\Controllers\Controller;
use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Core\Models\Attachment;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Optical\Models\Prescription;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * Serves private financial documents after checking permission and branch access.
 * Files never live under the public web root.
 */
class AttachmentController extends Controller
{
    public function __invoke(Attachment $attachment, BranchAccess $branches)
    {
        match ($attachment->attachable_type) {
            'expense' => [Gate::authorize('expenses.view'), $branches->authorize(Expense::findOrFail($attachment->attachable_id)->branch_id)],
            'prescription' => [Gate::authorize('prescriptions.view'), $branches->authorize(Prescription::findOrFail($attachment->attachable_id)->branch_id)],
            default => abort(404),
        };
        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404);

        $safeName = preg_replace('/[^\pL\pN_\-. ]/u', '_', $attachment->original_name);

        return Storage::disk($attachment->disk)->response($attachment->path, $safeName, [
            'Content-Type' => $attachment->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
            'Cache-Control' => 'private, no-store',
        ], 'inline');
    }
}
