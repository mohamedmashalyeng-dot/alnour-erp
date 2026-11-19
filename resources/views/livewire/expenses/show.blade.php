<div>
    <x-page-header :title="__('Expense').' '.$e->number" :subtitle="$e->description">
        <x-slot:actions>
            @if ($canEdit)
                <a href="{{ route('expenses.edit', $e) }}" class="btn-secondary">{{ __('Edit') }}</a>
                @if ($e->status === 'draft')<button wire:click="submit" class="btn-primary">{{ __('Submit for approval') }}</button>@endif
            @endif
            @if ($canApprove)<button wire:click="approve" wire:confirm="{{ __('Approve and post this expense to the ledger?') }}" class="btn-success">{{ __('Approve') }}</button>@endif
            <a href="{{ route('expenses.index') }}" class="btn-secondary">{{ __('Back') }}</a>
        </x-slot:actions>
    </x-page-header>
    @error('status') <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ $message }}</div> @enderror

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="card card-body space-y-2 text-sm xl:col-span-2">
            <div class="mb-2"><x-status-badge :color="['draft' => 'slate', 'submitted' => 'amber', 'approved' => 'emerald', 'rejected' => 'rose'][$e->status]" class="text-sm">{{ __('expense.'.$e->status) }}</x-status-badge></div>
            <div class="flex justify-between"><span class="text-slate-500">{{ __('Branch') }}</span><span>{{ $e->branch->displayName() }}</span></div>
            <div class="flex justify-between"><span class="text-slate-500">{{ __('Category') }}</span><span>{{ $e->category->displayName() }}</span></div>
            <div class="flex justify-between"><span class="text-slate-500">{{ __('Expense date') }}</span><span dir="ltr">{{ $e->expense_date->format('Y-m-d') }}</span></div>
            <div class="flex justify-between"><span class="text-slate-500">{{ __('Payment method') }}</span>
                <span>{{ ['cash' => __('Cash'), 'bank' => __('Bank'), 'payable' => __('Unpaid (accrued)')][$e->payment_source] }} {{ $e->cashRegister?->name ?? $e->bankAccount?->name }}</span></div>
            <div class="flex justify-between"><span class="text-slate-500">{{ __('Reference') }}</span><span dir="ltr">{{ $e->reference ?: '—' }}</span></div>
            <hr class="border-slate-200">
            <div class="flex justify-between"><span class="text-slate-500">{{ __('Amount (excl. tax)') }}</span><span>{{ money($e->amount) }}</span></div>
            <div class="flex justify-between"><span class="text-slate-500">{{ __('Recoverable VAT') }}</span><span>{{ money($e->tax_amount) }}</span></div>
            <div class="flex justify-between text-lg font-bold"><span>{{ __('Total') }}</span><span>{{ money($e->total) }}</span></div>
            <hr class="border-slate-200">
            <div class="flex justify-between"><span class="text-slate-500">{{ __('Created by') }}</span><span>{{ $e->creator->name }}</span></div>
            @if ($e->approver)<div class="flex justify-between"><span class="text-slate-500">{{ __('Approved by') }}</span><span>{{ $e->approver->name }} · <span dir="ltr">{{ $e->approved_at?->format('Y-m-d H:i') }}</span></span></div>@endif
            @if ($e->rejection_reason)<p class="rounded-lg bg-rose-50 p-2 text-rose-800">{{ __('Rejected: :reason', ['reason' => $e->rejection_reason]) }}</p>@endif
            @if ($entry)<div class="flex justify-between"><span class="text-slate-500">{{ __('Journal entry') }}</span><a href="{{ route('accounting.journal.show', $entry) }}" class="font-mono text-brand-700" dir="ltr">{{ $entry->number }}</a></div>@endif
        </div>
        <div class="space-y-6">
            <div class="card card-body">
                <h3 class="mb-3 font-semibold">{{ __('Receipts / invoices') }}</h3>
                <ul class="space-y-2 text-sm">
                    @forelse ($e->attachments as $a)
                        <li class="flex items-center justify-between gap-2">
                            <a href="{{ route('attachments.show', $a) }}" target="_blank" class="truncate text-brand-700 hover:underline">{{ $a->original_name }}</a>
                            @if ($canEdit)<button wire:click="removeAttachment({{ $a->id }})" wire:confirm="{{ __('Are you sure?') }}" class="text-rose-600"><x-icon name="trash" class="size-4" /></button>@endif
                        </li>
                    @empty
                        <li class="text-slate-500">{{ __('No attachments.') }}</li>
                    @endforelse
                </ul>
                @if ($e->status !== 'approved')
                    @can('expenses.create')
                        <form wire:submit="attach" class="mt-3 space-y-2">
                            <input type="file" wire:model="upload" accept="application/pdf,image/jpeg,image/png,image/webp" class="text-sm">
                            @error('upload') <p class="form-error">{{ $message }}</p> @enderror
                            <button class="btn-secondary btn-sm">{{ __('Upload') }}</button>
                        </form>
                    @endcan
                @endif
            </div>
            @if ($canApprove)
                <form wire:submit="reject" class="card card-body space-y-2">
                    <label class="form-label">{{ __('Rejection reason') }}</label>
                    <input wire:model="rejectReason" class="form-input">
                    @error('rejectReason') <p class="form-error">{{ $message }}</p> @enderror
                    <button class="btn-danger">{{ __('Reject') }}</button>
                </form>
            @endif
        </div>
    </div>
</div>
