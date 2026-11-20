<?php

namespace App\Modules\Website\Livewire;

use App\Modules\Website\Enums\InquiryStatus;
use App\Modules\Website\Enums\InquiryType;
use App\Modules\Website\Models\WebsiteInquiry;
use App\Modules\Website\Services\InquiryService;
use App\Modules\Website\Support\SiteInfo;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Website requests')]
class InquiryIndex extends Component
{
    use WithPagination;

    #[Url]
    public string $status = 'open';

    #[Url]
    public string $type = '';

    #[Url]
    public string $search = '';

    #[Locked]
    public ?int $noting = null;

    public string $note = '';

    public function mount(): void
    {
        $this->authorize('website.inquiries');
    }

    public function updating($name): void
    {
        if (in_array($name, ['status', 'type', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function move(int $id, string $status, InquiryService $service): void
    {
        $this->authorize('website.inquiries');
        $inquiry = $service->visibleQuery(auth()->user())->findOrFail($id);
        $service->setStatus($inquiry, InquiryStatus::from($status));
        $this->dispatch('toast', message: __('Saved successfully.'));
    }

    public function startNote(int $id, InquiryService $service): void
    {
        $this->authorize('website.inquiries');
        $inquiry = $service->visibleQuery(auth()->user())->findOrFail($id);
        $this->noting = $inquiry->id;
        $this->note = (string) $inquiry->staff_note;
    }

    public function saveNote(InquiryService $service): void
    {
        $this->authorize('website.inquiries');
        $this->validate(['note' => ['nullable', 'string', 'max:1000']]);
        $inquiry = $service->visibleQuery(auth()->user())->findOrFail($this->noting);
        $next = $inquiry->status === InquiryStatus::New ? InquiryStatus::Contacted : $inquiry->status;
        $service->setStatus($inquiry, $next, $this->note);
        $this->noting = null;
        $this->note = '';
        $this->dispatch('toast', message: __('Saved successfully.'));
    }

    public function render(InquiryService $service, SiteInfo $site)
    {
        $q = $service->visibleQuery(auth()->user())->with(['product:id,name,name_en,sku', 'branch:id,name,name_en', 'handler:id,name']);
        match ($this->status) {
            'open' => $q->whereIn('status', [InquiryStatus::New->value, InquiryStatus::Contacted->value]),
            '' => null,
            default => $q->where('status', $this->status),
        };
        if ($this->type !== '') {
            $q->where('type', $this->type);
        }
        if (trim($this->search) !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($this->search)).'%';
            $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('phone', 'like', $like));
        }

        $counts = $service->visibleQuery(auth()->user())->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return view('livewire.website.inquiries', [
            'inquiries' => $q->latest('id')->paginate(20),
            'counts' => $counts,
            'statuses' => InquiryStatus::cases(),
            'types' => InquiryType::cases(),
            'countryCode' => (string) $site->get('whatsapp_country_code'),
        ]);
    }

    public static function whatsapp(WebsiteInquiry $i, string $countryCode): ?string
    {
        $n = SiteInfo::internationalNumber($i->phone, $countryCode);

        return $n ? 'https://wa.me/'.$n : null;
    }
}
