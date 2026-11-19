<?php

namespace App\Modules\Accounting\Livewire;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\LedgerService;
use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Core\Support\Money;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Manual journal entry: saved as a draft, posted by a user with accounting.journal.post. */
#[Title('Manual journal entry')]
class JournalForm extends Component
{
    public string $date = '';

    public ?int $branchId = null;

    public string $memo = '';

    /** @var list<array{account_id:string, debit:string, credit:string, description:string}> */
    public array $lines = [];

    public function mount(BranchAccess $access): void
    {
        $this->authorize('accounting.journal.create');
        $this->date = today()->toDateString();
        $this->branchId = $access->currentOperational();
        $this->lines = [$this->blank(), $this->blank()];
    }

    private function blank(): array
    {
        return ['account_id' => '', 'debit' => '', 'credit' => '', 'description' => ''];
    }

    public function addLine(): void
    {
        $this->lines[] = $this->blank();
    }

    public function removeLine(int $i): void
    {
        unset($this->lines[$i]);
        $this->lines = array_values($this->lines);
    }

    public function save(LedgerService $ledger, BranchAccess $access)
    {
        $this->validate([
            'date' => ['required', 'date'],
            'branchId' => ['required', 'integer'],
            'memo' => ['required', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:2', 'max:50'],
            'lines.*.account_id' => ['required', 'integer', 'exists:accounts,id'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
        ]);
        $access->authorizeOperational((int) $this->branchId);

        $lines = [];
        foreach ($this->lines as $i => $l) {
            $lines[] = [
                'account' => (int) $l['account_id'],
                'debit' => $this->amount($l['debit'], "lines.$i.debit"),
                'credit' => $this->amount($l['credit'], "lines.$i.credit"),
                'description' => $l['description'] ?: null,
                'branch_id' => (int) $this->branchId,
            ];
        }
        $entry = $ledger->createManual(['entry_date' => $this->date, 'branch_id' => (int) $this->branchId, 'memo' => $this->memo], $lines);
        session()->flash('success', __('Draft :number saved. It must be posted to affect the books.', ['number' => $entry->number]));

        return $this->redirectRoute('accounting.journal.show', $entry);
    }

    private function amount(?string $v, string $field): int
    {
        $v = trim((string) $v);
        if ($v === '') {
            return 0;
        }
        if (! Money::isValid($v) || Money::parse($v) < 0) {
            throw ValidationException::withMessages([$field => __('Invalid amount.')]);
        }

        return Money::parse($v);
    }

    public function render(BranchAccess $access)
    {
        $debit = 0;
        $credit = 0;
        foreach ($this->lines as $l) {
            $debit += Money::isValid($l['debit'] ?: '0') ? max(0, Money::parse($l['debit'] ?: '0')) : 0;
            $credit += Money::isValid($l['credit'] ?: '0') ? max(0, Money::parse($l['credit'] ?: '0')) : 0;
        }

        return view('livewire.accounting.journal-form', [
            'accounts' => Account::where('is_postable', true)->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'name_en']),
            'branches' => $access->allowedBranches(),
            'debit' => $debit, 'credit' => $credit,
        ]);
    }
}
