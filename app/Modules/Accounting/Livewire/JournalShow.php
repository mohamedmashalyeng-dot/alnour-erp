<?php

namespace App\Modules\Accounting\Livewire;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\LedgerService;
use App\Modules\Branches\Services\BranchAccess;
use Livewire\Attributes\Locked;
use Livewire\Component;

class JournalShow extends Component
{
    #[Locked]
    public int $entryId;

    public string $reason = '';

    public bool $showReverse = false;

    public function mount(JournalEntry $entry, BranchAccess $access): void
    {
        $this->authorize('accounting.view');
        $branches = $entry->lines()->distinct()->pluck('branch_id');
        abort_unless($branches->contains(fn ($b) => $access->canAccess((int) $b)), 403);
        $this->entryId = $entry->id;
    }

    public function post(LedgerService $ledger): void
    {
        $ledger->postDraft(JournalEntry::findOrFail($this->entryId));
        $this->dispatch('toast', message: __('Entry posted.'));
    }

    public function deleteDraft(LedgerService $ledger)
    {
        $ledger->deleteDraft(JournalEntry::findOrFail($this->entryId));
        session()->flash('success', __('Draft deleted.'));

        return $this->redirectRoute('accounting.journal');
    }

    public function reverse(LedgerService $ledger): void
    {
        $this->authorize('accounting.journal.post');
        $this->validate(['reason' => ['required', 'string', 'max:200']]);
        $ledger->reverse(JournalEntry::findOrFail($this->entryId), $this->reason);
        $this->showReverse = false;
        $this->dispatch('toast', message: __('Reversal entry posted.'));
    }

    public function render()
    {
        $entry = JournalEntry::with(['lines.account', 'lines.branch:id,code', 'creator:id,name', 'poster:id,name', 'reversalOf:id,number', 'reversedBy:id,number'])->findOrFail($this->entryId);

        return view('livewire.accounting.journal-show', ['e' => $entry])->title(__('Journal entry').' '.$entry->number);
    }
}
