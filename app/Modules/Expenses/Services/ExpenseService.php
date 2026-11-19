<?php

namespace App\Modules\Expenses\Services;

use App\Modules\Accounting\Services\LedgerService;
use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Core\Models\Attachment;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Services\DocumentNumberGenerator;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Expenses\Models\ExpenseCategory;
use App\Modules\Treasury\Models\BankAccount;
use App\Modules\Treasury\Models\CashRegister;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Expense workflow: draft → submitted → approved (posted to the ledger) | rejected.
 * An approver cannot approve their own expense (segregation of duties), except the super admin.
 */
class ExpenseService
{
    public function __construct(
        private LedgerService $ledger,
        private BranchAccess $branches,
        private DocumentNumberGenerator $numbers,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array{branch_id:int, expense_category_id:int, expense_date:string, description:string, amount:int, tax_amount?:int,
     *               payment_source:string, cash_register_id?:int|null, bank_account_id?:int|null, reference?:string|null, uuid?:string}  $data
     */
    public function save(array $data, ?Expense $expense = null): Expense
    {
        Gate::authorize('expenses.create');
        $data = Validator::make($data, [
            'branch_id' => ['required', 'integer'],
            'expense_category_id' => ['required', 'integer', Rule::exists('expense_categories', 'id')->where('is_active', true)],
            'expense_date' => ['required', 'date', 'before_or_equal:today'],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'integer', 'min:1'],
            'tax_amount' => ['nullable', 'integer', 'min:0'],
            'payment_source' => ['required', Rule::in(['cash', 'bank', 'payable'])],
            'cash_register_id' => ['nullable', 'required_if:payment_source,cash', 'integer'],
            'bank_account_id' => ['nullable', 'required_if:payment_source,bank', 'integer', 'exists:bank_accounts,id'],
            'reference' => ['nullable', 'string', 'max:100'],
            'uuid' => ['nullable', 'uuid'],
        ])->validate();
        $branch = $this->branches->authorizeOperational((int) $data['branch_id']);
        if ($data['payment_source'] === 'cash') {
            CashRegister::where('branch_id', $branch->id)->where('is_active', true)->findOr($data['cash_register_id'],
                fn () => throw ValidationException::withMessages(['cash_register_id' => __('Invalid cash register.')]));
        }

        return DB::transaction(function () use ($data, $expense) {
            if ($expense) {
                $expense = Expense::lockForUpdate()->findOrFail($expense->id);
                $this->branches->authorize($expense->branch_id);
                if (! in_array($expense->status, ['draft', 'rejected'], true)) {
                    throw ValidationException::withMessages(['status' => __('Only draft or rejected expenses can be edited.')]);
                }
            } else {
                $uuid = $data['uuid'] ?? (string) Str::uuid();
                if ($existing = Expense::where('uuid', $uuid)->first()) {
                    return $existing;
                }
                $expense = new Expense(['uuid' => $uuid]);
                $expense->number = $this->numbers->next('expense');
                $expense->created_by = auth()->id();
            }
            $tax = (int) ($data['tax_amount'] ?? 0);
            $expense->fill([
                'branch_id' => $data['branch_id'],
                'expense_category_id' => $data['expense_category_id'],
                'expense_date' => $data['expense_date'],
                'description' => $data['description'],
                'amount' => $data['amount'],
                'tax_amount' => $tax,
                'total' => $data['amount'] + $tax,
                'payment_source' => $data['payment_source'],
                'cash_register_id' => $data['payment_source'] === 'cash' ? $data['cash_register_id'] : null,
                'bank_account_id' => $data['payment_source'] === 'bank' ? $data['bank_account_id'] : null,
                'reference' => $data['reference'] ?? null,
            ]);
            $expense->status = 'draft';
            $expense->rejection_reason = null;
            $expense->save();
            $this->audit->log($expense->wasRecentlyCreated ? 'expense.created' : 'expense.updated', $expense, [], ['total' => $expense->total], $expense->branch_id);

            return $expense;
        });
    }

    public function submit(Expense $expense): Expense
    {
        Gate::authorize('expenses.create');

        return $this->transition($expense, ['draft'], 'submitted');
    }

    public function approve(Expense $expense): Expense
    {
        Gate::authorize('expenses.approve');
        $this->branches->authorizeOperational($expense->branch_id);
        if ($expense->created_by === auth()->id() && ! auth()->user()->isSuperAdmin()) {
            throw ValidationException::withMessages(['status' => __('You cannot approve an expense you created.')]);
        }

        return DB::transaction(function () use ($expense) {
            $locked = $this->transition($expense, ['submitted'], 'approved', function (Expense $e) {
                $e->approved_by = auth()->id();
                $e->approved_at = now();
            });
            $this->post($locked);

            return $locked;
        });
    }

    public function reject(Expense $expense, string $reason): Expense
    {
        Gate::authorize('expenses.approve');
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['rejection_reason' => __('A reason is required (max 255 characters).')]);
        }

        return $this->transition($expense, ['submitted'], 'rejected', fn (Expense $e) => $e->rejection_reason = mb_substr($reason, 0, 255));
    }

    /** Dr expense account (+ VAT input) / Cr cash register, bank or accrued expenses. */
    public function post(Expense $expense): void
    {
        $credit = match ($expense->payment_source) {
            'cash' => CashRegister::findOrFail($expense->cash_register_id)->account_id,
            'bank' => BankAccount::findOrFail($expense->bank_account_id)->account_id,
            default => 'accrued.expenses',
        };
        $this->ledger->post([
            'entry_date' => $expense->expense_date, 'branch_id' => $expense->branch_id, 'source' => $expense, 'event' => 'expense',
            'memo' => __('Expense :number', ['number' => $expense->number]).' — '.$expense->description,
        ], [
            ['account' => ExpenseCategory::findOrFail($expense->expense_category_id)->account_id, 'debit' => $expense->amount],
            ['account' => 'vat.input', 'debit' => $expense->tax_amount],
            ['account' => $credit, 'credit' => $expense->total],
        ]);
    }

    /** Private receipt / invoice upload (PDF or image), served only through an authorized route. */
    public function attach(Expense $expense, UploadedFile $file): Attachment
    {
        Gate::authorize('expenses.create');
        $this->branches->authorize($expense->branch_id);
        Validator::make(['file' => $file], ['file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120']])->validate();

        $ext = match ($file->getMimeType()) {
            'application/pdf' => 'pdf', 'image/png' => 'png', 'image/webp' => 'webp', default => 'jpg',
        };
        $path = $file->storeAs('attachments/expenses/'.$expense->id, Str::random(40).'.'.$ext, 'local');
        $attachment = $expense->attachments()->create([
            'disk' => 'local', 'path' => $path, 'original_name' => mb_substr($file->getClientOriginalName(), 0, 200),
            'mime' => $file->getMimeType(), 'size' => $file->getSize(), 'uploaded_by' => auth()->id(),
        ]);
        $this->audit->log('expense.attachment_added', $expense, [], ['attachment' => $attachment->id], $expense->branch_id);

        return $attachment;
    }

    public function removeAttachment(Attachment $attachment): void
    {
        Gate::authorize('expenses.create');
        $expense = Expense::findOrFail($attachment->attachable_id);
        $this->branches->authorize($expense->branch_id);
        if ($expense->status === 'approved') {
            throw ValidationException::withMessages(['status' => __('Approved expenses cannot be modified.')]);
        }
        Storage::disk($attachment->disk)->delete($attachment->path);
        $attachment->delete();
    }

    private function transition(Expense $expense, array $from, string $to, ?\Closure $apply = null): Expense
    {
        return DB::transaction(function () use ($expense, $from, $to, $apply) {
            $locked = Expense::lockForUpdate()->findOrFail($expense->id);
            $this->branches->authorize($locked->branch_id);
            if (! in_array($locked->status, $from, true)) {
                throw ValidationException::withMessages(['status' => __('This expense cannot be moved to :status.', ['status' => __('expense.'.$to)])]);
            }
            $old = $locked->status;
            $locked->status = $to;
            if ($apply) {
                $apply($locked);
            }
            $locked->save();
            $this->audit->log('expense.'.$to, $locked, ['status' => $old], ['status' => $to], $locked->branch_id);

            return $locked;
        });
    }
}
