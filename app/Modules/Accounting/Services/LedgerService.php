<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Services\DocumentNumberGenerator;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The ONLY writer of journal entries.
 *
 *  - every posted entry is balanced (Σ debit = Σ credit), has ≥ 2 lines, and each line has exactly one side;
 *  - only active, postable accounts can be used;
 *  - the entry date must fall in an open accounting period;
 *  - posting is idempotent per (source_type, source_id, source_event) — enforced by a unique index;
 *  - posted entries are never edited or deleted: corrections are reversal entries.
 */
class LedgerService
{
    public function __construct(
        private AccountResolver $accounts,
        private PeriodService $periods,
        private DocumentNumberGenerator $numbers,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array{entry_date:mixed, branch_id?:int|null, source?:Model|null, source_type?:string|null, source_id?:int|null,
     *               event?:string|null, memo?:string|null, status?:string, repost?:bool}  $header
     * @param  list<array{account:string|int|Account, debit?:int, credit?:int, branch_id?:int|null, party_type?:string|null, party_id?:int|null, description?:string|null}>  $lines
     */
    public function post(array $header, array $lines, bool $skipAccountChecks = false): JournalEntry
    {
        $status = $header['status'] ?? 'posted';
        [$sourceType, $sourceId] = $this->source($header);
        $event = $header['event'] ?? null;

        if ($sourceType && $event) {
            $existing = JournalEntry::where(['source_type' => $sourceType, 'source_id' => $sourceId])
                ->where(fn ($q) => $q->where('source_event', $event)->orWhere('source_event', 'like', $event.'#%'))->orderByDesc('id')->first();
            if ($existing && ! (($header['repost'] ?? false) && $existing->status === 'reversed')) {
                return $existing;
            }
            if ($existing) {
                $event = $event.'#'.(JournalEntry::where(['source_type' => $sourceType, 'source_id' => $sourceId])->where(fn ($q) => $q->where('source_event', $event)->orWhere('source_event', 'like', $event.'#%'))->count() + 1);
            }
        }

        $prepared = $this->prepare($lines, $header['branch_id'] ?? null, $skipAccountChecks);
        $date = Carbon::parse($header['entry_date'])->toDateString();
        if ($status === 'posted') {
            $this->periods->assertOpen($date);
        }

        try {
            return DB::transaction(function () use ($header, $prepared, $date, $status, $sourceType, $sourceId, $event) {
                $entry = JournalEntry::create([
                    'number' => $this->numbers->next('journal'),
                    'entry_date' => $date,
                    'branch_id' => $header['branch_id'] ?? $prepared[0]['branch_id'],
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'source_event' => $event,
                    'status' => $status,
                    'memo' => isset($header['memo']) ? mb_substr((string) $header['memo'], 0, 255) : null,
                    'total' => array_sum(array_column($prepared, 'debit')),
                    'created_by' => auth()->id(),
                    'posted_by' => $status === 'posted' ? auth()->id() : null,
                    'posted_at' => $status === 'posted' ? now() : null,
                ]);
                foreach ($prepared as $line) {
                    $entry->lines()->create($line);
                }

                return $entry;
            });
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent request posted the same source event first.
            if ($sourceType && $event) {
                return JournalEntry::where(['source_type' => $sourceType, 'source_id' => $sourceId, 'source_event' => $event])->firstOrFail();
            }
            throw $e;
        }
    }

    /**
     * Post a mirror-image entry and mark the original as reversed. Idempotent.
     */
    public function reverse(JournalEntry $entry, ?string $reason = null, ?string $date = null): JournalEntry
    {
        return DB::transaction(function () use ($entry, $reason, $date) {
            /** @var JournalEntry $original */
            $original = JournalEntry::lockForUpdate()->with('lines')->findOrFail($entry->id);
            if ($original->status === 'reversed' && $original->reversed_by_id) {
                return JournalEntry::findOrFail($original->reversed_by_id);
            }
            if ($original->status !== 'posted') {
                throw ValidationException::withMessages(['entry' => __('Only posted entries can be reversed.')]);
            }

            $lines = $original->lines->map(fn ($l) => [
                'account' => $l->account_id,
                'branch_id' => $l->branch_id,
                'debit' => $l->credit,
                'credit' => $l->debit,
                'party_type' => $l->party_type,
                'party_id' => $l->party_id,
                'description' => $l->description,
            ])->all();

            $reversal = $this->post([
                'entry_date' => $date ?? now()->toDateString(),
                'branch_id' => $original->branch_id,
                'source_type' => 'journal_entry',
                'source_id' => $original->id,
                'event' => 'reversal',
                'memo' => __('Reversal of :number', ['number' => $original->number]).($reason ? ' — '.$reason : ''),
            ], $lines, skipAccountChecks: true);
            $reversal->reversal_of_id = $original->id;
            $reversal->saveQuietly();

            $original->status = 'reversed';
            $original->reversed_by_id = $reversal->id;
            $original->save();
            $this->audit->log('accounting.reversed', $original, [], ['reversal' => $reversal->number, 'reason' => $reason], $original->branch_id);

            return $reversal;
        });
    }

    /** Reverse the entry posted for a document event, if any. */
    public function reverseSource(Model $source, string $event, ?string $reason = null): ?JournalEntry
    {
        $entry = JournalEntry::where(['source_type' => $source->getMorphClass(), 'source_id' => $source->getKey(), 'source_event' => $event])
            ->where('status', 'posted')->first();

        return $entry ? $this->reverse($entry, $reason) : null;
    }

    // ---------------------------------------------------------------- manual journals

    public function createManual(array $header, array $lines): JournalEntry
    {
        Gate::authorize('accounting.journal.create');
        $entry = $this->post($header + ['status' => 'draft'], $lines);
        $this->audit->log('accounting.manual_created', $entry, [], ['total' => $entry->total], $entry->branch_id);

        return $entry;
    }

    public function postDraft(JournalEntry $entry): JournalEntry
    {
        Gate::authorize('accounting.journal.post');

        return DB::transaction(function () use ($entry) {
            $locked = JournalEntry::lockForUpdate()->with('lines')->findOrFail($entry->id);
            if ($locked->status !== 'draft') {
                throw ValidationException::withMessages(['entry' => __('This entry is already posted.')]);
            }
            // Re-validate against the current chart and period state.
            $this->prepare($locked->lines->map(fn ($l) => ['account' => $l->account_id, 'debit' => $l->debit, 'credit' => $l->credit, 'branch_id' => $l->branch_id])->all(), $locked->branch_id);
            $this->periods->assertOpen($locked->entry_date);
            $locked->status = 'posted';
            $locked->posted_by = auth()->id();
            $locked->posted_at = now();
            $locked->save();
            $this->audit->log('accounting.manual_posted', $locked, [], ['total' => $locked->total], $locked->branch_id);

            return $locked;
        });
    }

    public function deleteDraft(JournalEntry $entry): void
    {
        Gate::authorize('accounting.journal.create');
        DB::transaction(function () use ($entry) {
            $locked = JournalEntry::lockForUpdate()->findOrFail($entry->id);
            if ($locked->status !== 'draft') {
                throw ValidationException::withMessages(['entry' => __('Posted entries cannot be deleted. Reverse them instead.')]);
            }
            $locked->lines()->delete();
            $locked->delete();
        });
    }

    // ---------------------------------------------------------------- internals

    /**
     * Validate and normalize lines. Throws on any rule violation.
     *
     * @return list<array{account_id:int, branch_id:int, debit:int, credit:int, party_type:?string, party_id:?int, description:?string}>
     */
    public function prepare(array $lines, ?int $defaultBranch, bool $skipAccountChecks = false): array
    {
        $out = [];
        $debit = 0;
        $credit = 0;
        foreach ($lines as $i => $line) {
            $d = (int) ($line['debit'] ?? 0);
            $c = (int) ($line['credit'] ?? 0);
            if ($d === 0 && $c === 0) {
                continue; // zero lines are dropped
            }
            if ($d < 0 || $c < 0 || ($d > 0 && $c > 0)) {
                throw ValidationException::withMessages(["lines.$i" => __('Each line must have either a debit or a credit amount.')]);
            }
            $accountId = $this->accountId($line['account']);
            if (! $skipAccountChecks) {
                $account = Account::find($accountId);
                if (! $account || ! $account->is_active || ! $account->is_postable) {
                    throw ValidationException::withMessages(["lines.$i" => __('Account :code cannot receive postings (inactive or a header account).', ['code' => $account?->code ?? $accountId])]);
                }
            }
            $branch = $line['branch_id'] ?? $defaultBranch;
            if (! $branch) {
                throw new InvalidArgumentException('Every journal line needs a branch.');
            }
            $out[] = [
                'account_id' => $accountId,
                'branch_id' => (int) $branch,
                'debit' => $d,
                'credit' => $c,
                'party_type' => $line['party_type'] ?? null,
                'party_id' => $line['party_id'] ?? null,
                'description' => isset($line['description']) ? mb_substr((string) $line['description'], 0, 255) : null,
            ];
            $debit += $d;
            $credit += $c;
        }

        if (count($out) < 2) {
            throw ValidationException::withMessages(['lines' => __('A journal entry needs at least two lines.')]);
        }
        if ($debit !== $credit) {
            throw ValidationException::withMessages(['lines' => __('The entry is not balanced: debits :debit, credits :credit.', ['debit' => money($debit), 'credit' => money($credit)])]);
        }

        return $out;
    }

    private function accountId(string|int|Account $account): int
    {
        return match (true) {
            $account instanceof Account => $account->id,
            is_int($account) => $account,
            default => $this->accounts->id($account),
        };
    }

    /** @return array{0:?string,1:?int} */
    private function source(array $header): array
    {
        if (($header['source'] ?? null) instanceof Model) {
            return [$header['source']->getMorphClass(), (int) $header['source']->getKey()];
        }

        return [$header['source_type'] ?? null, isset($header['source_id']) ? (int) $header['source_id'] : null];
    }
}
