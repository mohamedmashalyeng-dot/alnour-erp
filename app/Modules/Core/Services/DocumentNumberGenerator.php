<?php

namespace App\Modules\Core\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Gap-safe, concurrency-safe document numbers (invoices, transfers, payments ...).
 * Must be called inside a DB transaction; the sequence row is locked FOR UPDATE.
 */
class DocumentNumberGenerator
{
    public const PREFIXES = [
        'sale' => 'INV',
        'transfer' => 'TRF',
        'purchase' => 'PO',
        'payment' => 'PAY',
        'return' => 'RET',
        'journal' => 'JE',
        'expense' => 'EXP',
        'treasury' => 'TRM',
        'payroll' => 'PR',
        'prescription' => 'RX',
        'warranty_claim' => 'WC',
        'repair' => 'RPR',
    ];

    public function next(string $type, ?int $branchId = null, ?string $branchCode = null): string
    {
        $year = (int) now()->format('Y');
        $number = $this->nextRaw($type, $branchId ?? 0, $year);

        $prefix = self::PREFIXES[$type] ?? strtoupper($type);
        $parts = array_filter([$prefix, $branchCode, $year]);

        return implode('-', $parts).'-'.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Next integer in a locked sequence (type, branch, year). Year 0 = never resets.
     */
    public function nextRaw(string $type, int $branchId = 0, int $year = 0): int
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('Document numbers must be generated inside a transaction.');
        }

        $key = ['type' => $type, 'branch_id' => $branchId, 'year' => $year];

        // Ensure the row exists (insertOrIgnore avoids a race on first use), then lock it.
        DB::table('document_sequences')->insertOrIgnore($key + ['last_number' => 0, 'created_at' => now(), 'updated_at' => now()]);

        $row = DB::table('document_sequences')->where($key)->lockForUpdate()->first();
        $number = $row->last_number + 1;

        DB::table('document_sequences')->where('id', $row->id)->update(['last_number' => $number, 'updated_at' => now()]);

        return $number;
    }
}
