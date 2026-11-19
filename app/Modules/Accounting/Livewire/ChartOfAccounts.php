<?php

namespace App\Modules\Accounting\Livewire;

use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Services\AccountResolver;
use App\Modules\Accounting\Services\FinancialReports;
use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Core\Services\AuditLogger;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Chart of accounts')]
class ChartOfAccounts extends Component
{
    #[Url]
    public ?int $branch = null;

    public bool $showForm = false;

    #[Locked]
    public ?int $editingId = null;

    public array $form = [];

    public array $mappings = [];

    public bool $showMappings = false;

    public function mount(BranchAccess $access): void
    {
        $this->authorize('accounting.view');
        $this->branch ??= auth()->user()->canAccessAllBranches() ? 0 : $access->current();
        $this->resetForm();
        $this->mappings = AccountMapping::pluck('account_id', 'key')->map(fn ($v) => (string) $v)->all();
    }

    public function create(?int $parentId = null): void
    {
        $this->authorize('accounting.accounts.manage');
        $this->resetForm();
        if ($parentId && ($parent = Account::find($parentId))) {
            $this->form['parent_id'] = (string) $parent->id;
            $this->form['type'] = $parent->type->value;
            $this->form['code'] = $parent->code;
        }
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorize('accounting.accounts.manage');
        $a = Account::findOrFail($id);
        $this->editingId = $a->id;
        $this->form = [
            'code' => $a->code, 'name' => $a->name, 'name_en' => $a->name_en, 'description' => $a->description, 'description_en' => $a->description_en,
            'type' => $a->type->value, 'parent_id' => (string) $a->parent_id, 'is_postable' => $a->is_postable, 'is_active' => $a->is_active,
        ];
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(AuditLogger $audit): void
    {
        $this->authorize('accounting.accounts.manage');
        $data = $this->validate([
            'form.code' => ['required', 'string', 'max:32', 'regex:/^[0-9A-Za-z\-\.]+$/', Rule::unique('accounts', 'code')->ignore($this->editingId)],
            'form.name' => ['required', 'string', 'max:255'],
            'form.name_en' => ['nullable', 'string', 'max:255'],
            'form.description' => ['nullable', 'string', 'max:255'],
            'form.description_en' => ['nullable', 'string', 'max:255'],
            'form.type' => ['required', Rule::in(AccountType::values())],
            'form.parent_id' => ['nullable', 'integer', 'exists:accounts,id', Rule::notIn(array_filter([$this->editingId]))],
            'form.is_postable' => ['boolean'],
            'form.is_active' => ['boolean'],
        ])['form'];
        $data['parent_id'] = $data['parent_id'] ?: null;
        $data['normal_balance'] = AccountType::from($data['type'])->normalBalance();

        if ($this->editingId) {
            $account = Account::findOrFail($this->editingId);
            $hasPostings = JournalLine::where('account_id', $account->id)->exists();
            if ($hasPostings && ($data['type'] !== $account->type->value || ! $data['is_postable'])) {
                $this->addError('form.type', __('An account with postings cannot change type or become a header account.'));

                return;
            }
            if (! $data['is_active'] && AccountMapping::where('account_id', $account->id)->exists()) {
                $this->addError('form.is_active', __('This account is used by an automatic posting rule. Remap it first.'));

                return;
            }
            $original = $account->getAttributes();
            $account->update($data);
            $audit->updated($account, 'accounting.account', $original);
        } else {
            $audit->created(Account::create($data), 'accounting.account');
        }
        $this->showForm = false;
        $this->dispatch('toast', message: __('Saved successfully.'));
    }

    public function saveMappings(AuditLogger $audit, AccountResolver $resolver): void
    {
        $this->authorize('accounting.accounts.manage');
        foreach (AccountResolver::KEYS as $key => $code) {
            $id = (int) ($this->mappings[$key] ?? 0);
            $account = Account::where('is_postable', true)->where('is_active', true)->find($id);
            if (! $account) {
                $this->addError('mappings.'.$key, __('Choose an active postable account.'));

                return;
            }
        }
        $old = AccountMapping::pluck('account_id', 'key')->all();
        foreach (AccountResolver::KEYS as $key => $code) {
            AccountMapping::updateOrCreate(['key' => $key], ['account_id' => (int) $this->mappings[$key]]);
        }
        $resolver->flush();
        $audit->log('accounting.mappings_updated', null, $old, AccountMapping::pluck('account_id', 'key')->all());
        $this->dispatch('toast', message: __('Saved successfully.'));
    }

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->form = ['code' => '', 'name' => '', 'name_en' => '', 'description' => '', 'description_en' => '', 'type' => 'expense', 'parent_id' => '', 'is_postable' => true, 'is_active' => true];
        $this->resetValidation();
    }

    public function render(FinancialReports $reports, BranchAccess $access)
    {
        if ($this->branch) {
            $access->authorize((int) $this->branch);
            $ids = [(int) $this->branch];
        } else {
            abort_unless(auth()->user()->canAccessAllBranches(), 403);
            $ids = $access->allowedIds();
        }
        $balances = $reports->accountTotals($ids)->keyBy('id');
        $accounts = Account::orderBy('code')->get();

        // Roll child balances up into header accounts.
        $byId = $accounts->keyBy('id');
        $totals = [];
        foreach ($accounts as $a) {
            $own = isset($balances[$a->id]) ? $balances[$a->id]->debit - $balances[$a->id]->credit : 0;
            for ($node = $a; $node; $node = $node->parent_id ? $byId[$node->parent_id] ?? null : null) {
                $totals[$node->id] = ($totals[$node->id] ?? 0) + $own;
            }
        }
        $depth = function ($a) use ($byId) {
            $d = 0;
            while ($a->parent_id && isset($byId[$a->parent_id]) && $d < 10) {
                $a = $byId[$a->parent_id];
                $d++;
            }

            return $d;
        };

        return view('livewire.accounting.accounts', [
            'accounts' => $accounts,
            'totals' => $totals,
            'depth' => $depth,
            'types' => AccountType::options(),
            'postable' => $accounts->where('is_postable', true)->where('is_active', true),
            'mappingKeys' => array_keys(AccountResolver::KEYS),
            'branchOptions' => $access->allowedBranches(activeOnly: false),
            'canAll' => auth()->user()->canAccessAllBranches(),
        ]);
    }
}
