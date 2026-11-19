<div>
    <x-page-header :title="$expenseId ? __('Edit expense') : __('New expense')">
        <x-slot:actions><a href="{{ $expenseId ? route('expenses.show', $expenseId) : route('expenses.index') }}" class="btn-secondary">{{ __('Cancel') }}</a></x-slot:actions>
    </x-page-header>
    <div class="card card-body grid max-w-4xl gap-4 sm:grid-cols-2">
        <div>
            <label class="form-label">{{ __('Branch') }} *</label>
            <select wire:model.live="form.branch_id" class="form-input">@foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->displayName() }}</option>@endforeach</select>
            @error('form.branch_id') <p class="form-error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="form-label">{{ __('Category') }} *</label>
            <select wire:model="form.expense_category_id" class="form-input"><option value="">—</option>@foreach ($categories as $c)<option value="{{ $c->id }}">{{ $c->displayName() }}</option>@endforeach</select>
            @error('form.expense_category_id') <p class="form-error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="form-label">{{ __('Expense date') }} *</label>
            <input type="date" wire:model="form.expense_date" class="form-input">
            @error('form.expense_date') <p class="form-error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="form-label">{{ __('Description') }} *</label>
            <input wire:model="form.description" class="form-input" maxlength="255">
            @error('form.description') <p class="form-error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="form-label">{{ __('Amount (excl. tax)') }} *</label>
            <input wire:model="form.amount" inputmode="decimal" class="form-input" dir="ltr">
            @error('form.amount') <p class="form-error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="form-label">{{ __('Recoverable VAT') }}</label>
            <input wire:model="form.tax_amount" inputmode="decimal" class="form-input" dir="ltr" placeholder="0.00">
            @error('form.tax_amount') <p class="form-error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="form-label">{{ __('Payment method') }} *</label>
            <select wire:model.live="form.payment_source" class="form-input">
                <option value="cash">{{ __('Cash') }}</option><option value="bank">{{ __('Bank') }}</option><option value="payable">{{ __('Unpaid (accrued)') }}</option>
            </select>
        </div>
        @if ($form['payment_source'] === 'cash')
            <div>
                <label class="form-label">{{ __('Cash register') }} *</label>
                <select wire:model="form.cash_register_id" class="form-input"><option value="">—</option>@foreach ($registers as $r)<option value="{{ $r->id }}">{{ $r->name }}</option>@endforeach</select>
                @error('form.cash_register_id') <p class="form-error">{{ $message }}</p> @enderror
            </div>
        @elseif ($form['payment_source'] === 'bank')
            <div>
                <label class="form-label">{{ __('Bank account') }} *</label>
                <select wire:model="form.bank_account_id" class="form-input"><option value="">—</option>@foreach ($banks as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach</select>
                @error('form.bank_account_id') <p class="form-error">{{ $message }}</p> @enderror
            </div>
        @endif
        <div>
            <label class="form-label">{{ __('Reference') }}</label>
            <input wire:model="form.reference" class="form-input" dir="ltr">
        </div>
        <div class="sm:col-span-2">
            <label class="form-label">{{ __('Receipts / invoices') }}</label>
            <input type="file" wire:model="uploads" multiple accept="application/pdf,image/jpeg,image/png,image/webp" class="text-sm">
            <p class="mt-1 text-xs text-slate-500">{{ __('PDF or image, up to 5 MB each. Stored privately.') }}</p>
            @error('uploads.*') <p class="form-error">{{ $message }}</p> @enderror
        </div>
        <div class="flex justify-end gap-2 sm:col-span-2">
            <button wire:click="save" class="btn-secondary" wire:loading.attr="disabled">{{ __('Save draft') }}</button>
            <button wire:click="saveAndSubmit" class="btn-primary" wire:loading.attr="disabled">{{ __('Save & submit for approval') }}</button>
        </div>
    </div>
</div>
