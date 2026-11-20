<?php

namespace App\Modules\Website\Services;

use App\Models\User;
use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Website\Enums\InquiryStatus;
use App\Modules\Website\Models\WebsiteInquiry;
use Illuminate\Support\Facades\Gate;

/**
 * Website requests: created anonymously from the public site, handled by staff in the dashboard.
 */
class InquiryService
{
    public function __construct(private AuditLogger $audit, private BranchAccess $branches) {}

    /** @param array<string, mixed> $data already validated by the controller */
    public function submit(array $data, ?string $ip): WebsiteInquiry
    {
        $inquiry = new WebsiteInquiry($data);
        $inquiry->status = InquiryStatus::New;
        $inquiry->ip_hash = $ip ? hash_hmac('sha256', $ip, (string) config('app.key')) : null;
        $inquiry->save();

        return $inquiry;
    }

    public function setStatus(WebsiteInquiry $inquiry, InquiryStatus $status, ?string $note = null): WebsiteInquiry
    {
        Gate::authorize('website.inquiries');
        $this->authorizeBranch($inquiry);

        $old = ['status' => $inquiry->status->value];
        $inquiry->status = $status;
        if ($note !== null) {
            $inquiry->staff_note = $note;
        }
        $inquiry->handled_by = auth()->id();
        $inquiry->handled_at = now();
        $inquiry->save();

        $this->audit->log('website.inquiry_status', $inquiry, $old, ['status' => $status->value], $inquiry->branch_id);

        return $inquiry;
    }

    /** Inquiries for a specific branch are visible to that branch's staff; unassigned ones to everyone handling requests. */
    public function authorizeBranch(WebsiteInquiry $inquiry): void
    {
        if ($inquiry->branch_id !== null) {
            $this->branches->authorize($inquiry->branch_id);
        }
    }

    public function visibleQuery(User $user)
    {
        $q = WebsiteInquiry::query();
        if (! $user->canAccessAllBranches()) {
            $ids = $this->branches->allowedIds();
            $q->where(fn ($w) => $w->whereNull('branch_id')->orWhereIn('branch_id', $ids));
        }

        return $q;
    }
}
