<?php

namespace App\Modules\Core\Services;

use Illuminate\Support\Facades\DB;

/**
 * Read-only consistency checks across the ledgers. Never changes data: every problem is reported for a
 * human to investigate (stock adjustment, journal correction…) through the normal, audited processes.
 */
class IntegrityChecker
{
    /** @return array<string, array{ok:bool, count:int, sample:list<string>}> */
    public function run(): array
    {
        return [
            'stock_matches_ledger' => $this->stockMatchesLedger(),
            'stock_ledger_chain' => $this->ledgerChain(),
            'journal_entries_balanced' => $this->journalBalanced(),
            'trial_balance' => $this->trialBalance(),
            'sales_totals' => $this->salesTotals(),
            'sales_posted_to_ledger' => $this->salesPosted(),
            'sale_payments' => $this->salePayments(),
            'loyalty_balances' => $this->loyaltyBalances(),
        ];
    }

    /** inventory.quantity = Σ inventory_transactions.quantity for each branch/product. */
    private function stockMatchesLedger(): array
    {
        $rows = DB::table('inventory as i')
            ->leftJoinSub(DB::table('inventory_transactions')->selectRaw('branch_id, product_id, SUM(quantity) as q')->groupBy('branch_id', 'product_id'), 't',
                fn ($j) => $j->on('t.branch_id', '=', 'i.branch_id')->on('t.product_id', '=', 'i.product_id'))
            ->whereRaw('i.quantity <> COALESCE(t.q, 0)')
            ->get(['i.branch_id', 'i.product_id', 'i.quantity', 't.q']);

        return $this->result($rows->map(fn ($r) => "branch {$r->branch_id} product {$r->product_id}: stock {$r->quantity} ≠ ledger ".(int) $r->q));
    }

    /** Each movement's balance_after = balance_before + quantity. */
    private function ledgerChain(): array
    {
        $rows = DB::table('inventory_transactions')->whereRaw('balance_after <> balance_before + quantity')->limit(50)->get(['id']);

        return $this->result($rows->map(fn ($r) => "movement #{$r->id}"));
    }

    private function journalBalanced(): array
    {
        $rows = DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.status', '!=', 'draft')
            ->groupBy('journal_lines.journal_entry_id')->havingRaw('SUM(journal_lines.debit) <> SUM(journal_lines.credit)')
            ->selectRaw('journal_lines.journal_entry_id as id')->limit(50)->get();

        return $this->result($rows->map(fn ($r) => "journal entry #{$r->id}"));
    }

    private function trialBalance(): array
    {
        $t = DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.status', '!=', 'draft')->selectRaw('COALESCE(SUM(debit),0) as d, COALESCE(SUM(credit),0) as c')->first();

        return $this->result((int) $t->d === (int) $t->c ? [] : ['debits '.(int) $t->d.' ≠ credits '.(int) $t->c]);
    }

    /** Completed sales: header total = Σ line totals; paid ≤ total + refunds. */
    private function salesTotals(): array
    {
        $rows = DB::table('sales as s')
            ->leftJoinSub(DB::table('sale_items')->selectRaw('sale_id, SUM(line_total) as lt')->groupBy('sale_id'), 'i', 'i.sale_id', '=', 's.id')
            ->where('s.status', '!=', 'draft')->whereRaw('s.total <> COALESCE(i.lt, 0)')->limit(50)->get(['s.invoice_number', 's.total', 'i.lt']);

        return $this->result($rows->map(fn ($r) => "{$r->invoice_number}: total {$r->total} ≠ lines ".(int) $r->lt));
    }

    /** Every completed/partially paid/cancelled sale has its accounting entry. */
    private function salesPosted(): array
    {
        $rows = DB::table('sales as s')->whereIn('s.status', ['completed', 'partially_paid', 'refunded', 'cancelled'])
            ->whereNotExists(fn ($q) => $q->from('journal_entries as j')->whereColumn('j.source_id', 's.id')->where('j.source_type', 'sale'))
            ->limit(50)->pluck('s.invoice_number');

        return $this->result($rows->map(fn ($n) => "$n has no journal entry"));
    }

    /** sales.paid_amount equals the posted payment allocations to the sale. */
    private function salePayments(): array
    {
        if (! DB::getSchemaBuilder()->hasTable('payment_allocations')) {
            return $this->result([]);
        }
        $rows = DB::table('sales as s')
            ->leftJoinSub(DB::table('payment_allocations as a')->join('payments as p', 'p.id', '=', 'a.payment_id')
                ->where('p.direction', 'in')->where('p.status', 'posted')->where('a.allocatable_type', 'sale')
                ->selectRaw('a.allocatable_id as sale_id, SUM(a.amount) as paid')->groupBy('a.allocatable_id'), 'x', 'x.sale_id', '=', 's.id')
            ->where('s.status', '!=', 'draft')->whereRaw('s.paid_amount <> COALESCE(x.paid, 0)')->limit(50)->get(['s.invoice_number', 's.paid_amount', 'x.paid']);

        return $this->result($rows->map(fn ($r) => "{$r->invoice_number}: paid {$r->paid_amount} ≠ allocations ".(int) $r->paid));
    }

    private function loyaltyBalances(): array
    {
        if (! DB::getSchemaBuilder()->hasTable('loyalty_transactions')) {
            return $this->result([]);
        }
        $rows = DB::table('customers as c')
            ->leftJoinSub(DB::table('loyalty_transactions')->selectRaw('customer_id, SUM(points) as p')->groupBy('customer_id'), 'l', 'l.customer_id', '=', 'c.id')
            ->whereRaw('c.loyalty_points <> COALESCE(l.p, 0)')->limit(50)->get(['c.id', 'c.loyalty_points', 'l.p']);

        return $this->result($rows->map(fn ($r) => "customer #{$r->id}: {$r->loyalty_points} ≠ ledger ".(int) $r->p));
    }

    private function result(iterable $problems): array
    {
        $list = collect($problems)->values();

        return ['ok' => $list->isEmpty(), 'count' => $list->count(), 'sample' => $list->take(10)->all()];
    }
}
