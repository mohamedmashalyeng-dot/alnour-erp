<?php

namespace App\Modules\Treasury\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Services\AccountResolver;
use App\Modules\Accounting\Services\LedgerService;
use App\Modules\Branches\Models\Branch;
use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Services\DocumentNumberGenerator;
use App\Modules\Core\Services\Settings;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Models\Payment;
use App\Modules\Treasury\Models\BankAccount;
use App\Modules\Treasury\Models\BankReconciliation;
use App\Modules\Treasury\Models\CashRegister;
use App\Modules\Treasury\Models\CashShift;
use App\Modules\Treasury\Models\TreasuryMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Cash registers, cashier shifts, bank accounts, deposits / withdrawals / transfers and bank reconciliation.
 * Cash and bank balances are always read from the general ledger (account of each register / bank account).
 */
class TreasuryService
{
    public function __construct(
        private LedgerService $ledger,
        private AccountResolver $accounts,
        private BranchAccess $branches,
        private DocumentNumberGenerator $numbers,
        private Settings $settings,
        private AuditLogger $audit,
    ) {}

    // ---------------------------------------------------------------- setup

    /** Default register of a branch (created on first use with its own GL account under 1110). */
    public function defaultRegister(int $branchId): CashRegister
    {
        $register = CashRegister::where('branch_id', $branchId)->where('is_default', true)->first();

        return $register ?? $this->createRegister(Branch::findOrFail($branchId), __('Main register'), true);
    }

    public function createRegister(Branch $branch, string $name, bool $default = false): CashRegister
    {
        return DB::transaction(function () use ($branch, $name, $default) {
            $parent = Account::where('code', '1110')->firstOrFail();
            $seq = Account::where('parent_id', $parent->id)->count() + 1;
            do {
                $code = '1110-'.$branch->code.'-'.str_pad((string) $seq++, 2, '0', STR_PAD_LEFT);
            } while (Account::where('code', $code)->exists());
            $account = new Account(['code' => $code, 'name' => __('Cash').' — '.$branch->name.' — '.$name, 'name_en' => 'Cash — '.$branch->code.' — '.$name,
                'type' => 'asset', 'normal_balance' => 'debit', 'parent_id' => $parent->id, 'is_postable' => true, 'is_active' => true]);
            $account->is_system = true;
            $account->save();

            return CashRegister::create(['branch_id' => $branch->id, 'name' => $name, 'account_id' => $account->id, 'is_default' => $default, 'is_active' => true]);
        });
    }

    public function createBankAccount(array $data): BankAccount
    {
        Gate::authorize('bank.manage');

        return DB::transaction(function () use ($data) {
            $parent = Account::where('code', '1120')->firstOrFail();
            $seq = Account::where('parent_id', $parent->id)->count() + 1;
            do {
                $code = '1120-'.str_pad((string) $seq++, 2, '0', STR_PAD_LEFT);
            } while (Account::where('code', $code)->exists());
            $account = new Account(['code' => $code, 'name' => __('Bank').' — '.$data['name'], 'name_en' => 'Bank — '.$data['name'],
                'type' => 'asset', 'normal_balance' => 'debit', 'parent_id' => $parent->id, 'is_postable' => true, 'is_active' => true]);
            $account->is_system = true;
            $account->save();
            $bank = BankAccount::create(['name' => $data['name'], 'bank_name' => $data['bank_name'] ?? null, 'account_number' => $data['account_number'] ?? null,
                'iban' => $data['iban'] ?? null, 'branch_id' => $data['branch_id'] ?? null, 'account_id' => $account->id, 'is_active' => true]);
            $this->audit->created($bank, 'treasury.bank_account');

            return $bank;
        });
    }

    public function defaultBank(): BankAccount
    {
        return BankAccount::where('is_active', true)->orderByDesc('is_default')->orderBy('id')->first()
            ?? throw ValidationException::withMessages(['bank_account_id' => __('No bank account is configured.')]);
    }

    /** Ledger balance (debit − credit) of an account, optionally up to a date. */
    public function balance(int $accountId, ?string $asOf = null): int
    {
        $row = JournalLine::query()->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account_id', $accountId)->whereIn('journal_entries.status', ['posted', 'reversed'])
            ->when($asOf, fn ($q) => $q->where('journal_entries.entry_date', '<=', $asOf))
            ->selectRaw('COALESCE(SUM(journal_lines.debit),0) - COALESCE(SUM(journal_lines.credit),0) as bal')->first();

        return (int) $row->bal;
    }

    // ---------------------------------------------------------------- payment routing

    /**
     * Decide where a payment's money physically goes/comes from. Called by PaymentService before the
     * payment row is written. Cash receipts at the POS require an open shift when the setting is on.
     *
     * @return array{cash_register_id:?int, cash_shift_id:?int, bank_account_id:?int}
     */
    public function route(Branch $branch, PaymentMethod $method, string $direction, string $party, array $meta): array
    {
        $out = ['cash_register_id' => null, 'cash_shift_id' => null, 'bank_account_id' => null];

        if ($method === PaymentMethod::Cash) {
            if (! empty($meta['cash_register_id'])) {
                $register = CashRegister::where('branch_id', $branch->id)->where('is_active', true)->find($meta['cash_register_id'])
                    ?? throw ValidationException::withMessages(['cash_register_id' => __('Invalid cash register.')]);
                $out['cash_register_id'] = $register->id;
                $out['cash_shift_id'] = $register->openShift()?->id;

                return $out;
            }
            $shift = $this->openShiftFor((int) auth()->id(), $branch->id);
            if ($shift) {
                return ['cash_register_id' => $shift->cash_register_id, 'cash_shift_id' => $shift->id, 'bank_account_id' => null];
            }
            // Offline sales already happened: they are never rejected for a missing shift (cash goes to the default register).
            if ($party === 'customer' && $direction === Payment::IN && $this->settings->bool('cash_shift_required') && empty($meta['offline'])) {
                throw ValidationException::withMessages(['payments' => __('Open a cash shift before receiving cash payments.')]);
            }
            $out['cash_register_id'] = $this->defaultRegister($branch->id)->id;

            return $out;
        }

        if (in_array($method, [PaymentMethod::BankTransfer, PaymentMethod::Cheque], true)) {
            $bank = ! empty($meta['bank_account_id'])
                ? BankAccount::where('is_active', true)->findOrFail($meta['bank_account_id'])
                : $this->defaultBank();
            $out['bank_account_id'] = $bank->id;
        }

        return $out;
    }

    /** GL account that holds the money of a payment. */
    public function paymentAccountId(Payment $payment): int
    {
        return match ($payment->method) {
            PaymentMethod::Cash => CashRegister::findOrFail($payment->cash_register_id ?? $this->defaultRegister($payment->branch_id)->id)->account_id,
            PaymentMethod::Card => $this->accounts->id('card.clearing'),
            PaymentMethod::BankTransfer, PaymentMethod::Cheque => BankAccount::findOrFail($payment->bank_account_id ?? $this->defaultBank()->id)->account_id,
            PaymentMethod::StoreCredit => $this->accounts->id('customer.credit'),
        };
    }

    public function openShiftFor(int $userId, int $branchId): ?CashShift
    {
        return CashShift::where('user_id', $userId)->where('status', 'open')
            ->whereHas('register', fn ($q) => $q->where('branch_id', $branchId))->first();
    }

    // ---------------------------------------------------------------- shifts

    public function openShift(CashRegister $register, int $openingFloat, ?string $notes = null): CashShift
    {
        Gate::authorize('cash.shift');
        $this->branches->authorizeOperational($register->branch_id);
        if ($openingFloat < 0) {
            throw ValidationException::withMessages(['opening_float' => __('Invalid amount.')]);
        }

        return DB::transaction(function () use ($register, $openingFloat, $notes) {
            $locked = CashRegister::lockForUpdate()->findOrFail($register->id);
            if (! $locked->is_active) {
                throw ValidationException::withMessages(['register' => __('This cash register is inactive.')]);
            }
            if ($locked->openShift()) {
                throw ValidationException::withMessages(['register' => __('This register already has an open shift.')]);
            }
            if (CashShift::where('user_id', auth()->id())->where('status', 'open')->exists()) {
                throw ValidationException::withMessages(['register' => __('You already have an open shift.')]);
            }

            $shift = CashShift::create(['cash_register_id' => $locked->id, 'user_id' => auth()->id(), 'opening_float' => $openingFloat, 'opened_at' => now(), 'notes' => $notes]);
            $shift->status = 'open';
            $shift->save();

            // The counted opening float is the truth: post any difference from the ledger balance.
            $diff = $openingFloat - $this->balance($locked->account_id);
            $this->postVariance($shift, $locked, $diff, 'opening_variance');
            $this->audit->log('cash.shift_opened', $shift, [], ['register' => $locked->name, 'float' => $openingFloat, 'variance' => $diff], $locked->branch_id);

            return $shift;
        });
    }

    /**
     * Close a shift with the physically counted cash. Expected = float + cash in − cash out during the shift.
     * The variance is posted to "cash over / short".
     */
    public function closeShift(CashShift $shift, int $counted, ?string $notes = null): CashShift
    {
        Gate::authorize('cash.shift');

        return DB::transaction(function () use ($shift, $counted, $notes) {
            /** @var CashShift $locked */
            $locked = CashShift::lockForUpdate()->with('register')->findOrFail($shift->id);
            $this->branches->authorize($locked->register->branch_id);
            if ($locked->status !== 'open') {
                throw ValidationException::withMessages(['shift' => __('This shift is already closed.')]);
            }
            if ($locked->user_id !== auth()->id() && ! auth()->user()->can('cash.manage')) {
                abort(403);
            }
            if ($counted < 0) {
                throw ValidationException::withMessages(['counted' => __('Invalid amount.')]);
            }

            $expected = $this->expectedCash($locked);
            $variance = $counted - $expected;
            $locked->expected_cash = max(0, $expected);
            $locked->counted_cash = $counted;
            $locked->variance = $variance;
            $locked->status = 'closed';
            $locked->closed_at = now();
            $locked->closed_by = auth()->id();
            $locked->notes = trim(($locked->notes ? $locked->notes."\n" : '').($notes ?? '')) ?: null;
            $locked->save();

            $this->postVariance($locked, $locked->register, $variance, 'variance');
            $this->audit->log('cash.shift_closed', $locked, [], ['expected' => $expected, 'counted' => $counted, 'variance' => $variance], $locked->register->branch_id);

            return $locked;
        });
    }

    public function expectedCash(CashShift $shift): int
    {
        $payments = Payment::where('cash_shift_id', $shift->id)->where('status', 'posted')
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END),0) as net")->value('net');
        $in = (int) TreasuryMovement::where('cash_shift_id', $shift->id)->where('to_type', 'cash_register')->where('to_id', $shift->cash_register_id)->sum('amount');
        $out = (int) TreasuryMovement::where('cash_shift_id', $shift->id)->where('from_type', 'cash_register')->where('from_id', $shift->cash_register_id)->sum('amount');

        return $shift->opening_float + (int) $payments + $in - $out;
    }

    private function postVariance(CashShift $shift, CashRegister $register, int $variance, string $event): void
    {
        if ($variance === 0) {
            return;
        }
        $this->ledger->post([
            'entry_date' => now()->toDateString(), 'branch_id' => $register->branch_id, 'source' => $shift, 'event' => $event,
            'memo' => __('Cash count difference — :register', ['register' => $register->name]),
        ], [
            ['account' => $register->account_id, 'debit' => max(0, $variance), 'credit' => max(0, -$variance)],
            ['account' => 'cash.over_short', 'debit' => max(0, -$variance), 'credit' => max(0, $variance)],
        ]);
    }

    // ---------------------------------------------------------------- deposits / withdrawals / transfers

    /**
     * @param  array{type:string, branch_id:int, amount:int, date?:string, from?:CashRegister|BankAccount|null, to?:CashRegister|BankAccount|null,
     *               counter_account_id?:int|null, reference?:string|null, notes?:string|null, uuid?:string|null}  $data
     */
    public function recordMovement(array $data): TreasuryMovement
    {
        $uuid = $data['uuid'] ?? (string) Str::uuid();
        if ($existing = TreasuryMovement::where('uuid', $uuid)->first()) {
            return $existing;
        }
        $from = $data['from'] ?? null;
        $to = $data['to'] ?? null;
        if (! $from && ! $to) {
            throw ValidationException::withMessages(['to' => __('Choose two different cash or bank accounts.')]);
        }
        foreach (array_filter([$from, $to]) as $side) {
            Gate::authorize($side instanceof BankAccount ? 'bank.manage' : 'cash.manage');
            if ($side instanceof CashRegister) {
                $this->branches->authorizeOperational($side->branch_id);
            }
        }
        $branch = $this->branches->authorizeOperational((int) $data['branch_id']);
        $amount = (int) $data['amount'];
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => __('Amount must be greater than zero.')]);
        }
        $type = $data['type'];
        if ($type === 'transfer' && (! $from || ! $to || ($from::class === $to::class && $from->id === $to->id))) {
            throw ValidationException::withMessages(['to' => __('Choose two different cash or bank accounts.')]);
        }
        if (in_array($type, ['deposit', 'withdrawal'], true)) {
            if (($type === 'deposit' && ! $to) || ($type === 'withdrawal' && ! $from) || empty($data['counter_account_id'])) {
                throw ValidationException::withMessages(['counter_account_id' => __('Choose the account and the counter account.')]);
            }
        }
        if ($from && $this->balance($from->account_id) < $amount) {
            throw ValidationException::withMessages(['amount' => __('Insufficient balance in the source account.')]);
        }

        return DB::transaction(function () use ($data, $uuid, $from, $to, $branch, $amount, $type) {
            $shiftId = null;
            foreach ([$from, $to] as $side) {
                if ($side instanceof CashRegister) {
                    $shiftId ??= $side->openShift()?->id;
                }
            }
            $movement = TreasuryMovement::create([
                'uuid' => $uuid,
                'number' => $this->numbers->next('treasury', $branch->id, $branch->code),
                'type' => $type,
                'movement_date' => $data['date'] ?? now()->toDateString(),
                'branch_id' => $branch->id,
                'from_type' => $from?->getMorphClass(), 'from_id' => $from?->id,
                'to_type' => $to?->getMorphClass(), 'to_id' => $to?->id,
                'counter_account_id' => $data['counter_account_id'] ?? null,
                'cash_shift_id' => $shiftId,
                'amount' => $amount,
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'user_id' => auth()->id(),
            ]);

            $debitAccount = $to?->account_id ?? (int) $data['counter_account_id'];
            $creditAccount = $from?->account_id ?? (int) $data['counter_account_id'];
            $this->ledger->post([
                'entry_date' => $movement->movement_date, 'branch_id' => $branch->id, 'source' => $movement, 'event' => 'movement',
                'memo' => __('treasury.'.$type).' '.$movement->number.($movement->reference ? ' — '.$movement->reference : ''),
            ], [
                ['account' => $debitAccount, 'debit' => $amount],
                ['account' => $creditAccount, 'credit' => $amount],
            ]);
            $this->audit->log('treasury.'.$type, $movement, [], ['amount' => $amount], $branch->id);

            return $movement;
        });
    }

    // ---------------------------------------------------------------- bank reconciliation

    /** Unreconciled ledger lines of a bank account up to a date. */
    public function unreconciledLines(BankAccount $bank, ?string $upTo = null)
    {
        return JournalLine::query()->with('entry:id,number,entry_date,memo')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account_id', $bank->account_id)
            ->whereIn('journal_entries.status', ['posted', 'reversed'])
            ->when($upTo, fn ($q) => $q->where('journal_entries.entry_date', '<=', $upTo))
            ->whereNotExists(fn ($q) => $q->from('bank_reconciliation_lines')->whereColumn('bank_reconciliation_lines.journal_line_id', 'journal_lines.id'))
            ->orderBy('journal_entries.entry_date')->orderBy('journal_lines.id')
            ->select('journal_lines.*')->get();
    }

    public function reconciledBalance(BankAccount $bank): int
    {
        return (int) DB::table('bank_reconciliation_lines')
            ->join('journal_lines', 'journal_lines.id', '=', 'bank_reconciliation_lines.journal_line_id')
            ->where('journal_lines.account_id', $bank->account_id)
            ->selectRaw('COALESCE(SUM(journal_lines.debit),0) - COALESCE(SUM(journal_lines.credit),0) as bal')->value('bal');
    }

    /**
     * Mark ledger lines as cleared on the bank statement. The reconciled balance after ticking must
     * equal the statement ending balance exactly.
     *
     * @param  list<int>  $lineIds
     */
    public function reconcile(BankAccount $bank, string $statementDate, int $statementBalance, array $lineIds): BankReconciliation
    {
        Gate::authorize('bank.reconcile');

        return DB::transaction(function () use ($bank, $statementDate, $statementBalance, $lineIds) {
            BankAccount::lockForUpdate()->findOrFail($bank->id);
            $eligible = $this->unreconciledLines($bank, $statementDate)->keyBy('id');
            $selected = collect($lineIds)->map(fn ($id) => (int) $id)->unique();
            foreach ($selected as $id) {
                if (! $eligible->has($id)) {
                    throw ValidationException::withMessages(['lines' => __('A selected transaction is not eligible for this reconciliation.')]);
                }
            }
            $cleared = $selected->sum(fn ($id) => $eligible[$id]->debit - $eligible[$id]->credit);
            $result = $this->reconciledBalance($bank) + $cleared;
            if ($result !== $statementBalance) {
                throw ValidationException::withMessages(['statement_balance' => __('Out of balance by :diff. Reconciled balance :rec, statement :stmt.', [
                    'diff' => money($statementBalance - $result), 'rec' => money($result), 'stmt' => money($statementBalance),
                ])]);
            }
            $rec = BankReconciliation::create(['bank_account_id' => $bank->id, 'statement_date' => $statementDate, 'statement_balance' => $statementBalance, 'user_id' => auth()->id()]);
            foreach ($selected as $id) {
                DB::table('bank_reconciliation_lines')->insert(['bank_reconciliation_id' => $rec->id, 'journal_line_id' => $id]);
            }
            $this->audit->log('treasury.bank_reconciled', $rec, [], ['statement_balance' => $statementBalance, 'lines' => $selected->count()]);

            return $rec;
        });
    }
}
