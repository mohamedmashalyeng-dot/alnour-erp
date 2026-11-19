<div>
    <x-page-header :title="__('Users')">
        <x-slot:actions>
            @can('users.manage')
                <button wire:click="create" class="btn-primary"><x-icon name="plus" class="size-4" />{{ __('New user') }}</button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="card">
        <div class="border-b border-slate-200 p-3">
            <input wire:model.live.debounce.300ms="search" type="search" class="form-input max-w-sm" placeholder="{{ __('Search by name or email') }}">
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>{{ __('Name') }}</th><th>{{ __('Email') }}</th><th>{{ __('Role') }}</th><th>{{ __('Branches') }}</th><th>{{ __('Last login') }}</th><th>{{ __('Status') }}</th><th></th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                @foreach ($users as $u)
                    <tr wire:key="user-{{ $u->id }}">
                        <td class="font-medium">{{ $u->name }}</td>
                        <td dir="ltr">{{ $u->email }}</td>
                        <td>{{ $u->roles->map(fn ($r) => __('role.'.$r->name))->join('، ') }}</td>
                        <td class="max-w-xs truncate">{{ $u->isSuperAdmin() ? __('All branches') : $u->branches->map->code->join(', ') }}</td>
                        <td>{{ $u->last_login_at?->diffForHumans() ?? '—' }}</td>
                        <td>@if ($u->is_active)<x-status-badge color="emerald">{{ __('Active') }}</x-status-badge>@else<x-status-badge color="rose">{{ __('Inactive') }}</x-status-badge>@endif</td>
                        <td class="text-end">
                            @can('users.manage')
                                @if (! $u->isSuperAdmin() || auth()->user()->isSuperAdmin())
                                    <button wire:click="edit({{ $u->id }})" class="btn-secondary btn-sm">{{ __('Edit') }}</button>
                                @endif
                            @endcan
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="p-3">{{ $users->links() }}</div>
    </div>

    <x-modal show="showForm" :title="$editingId ? __('Edit user') : __('New user')">
        <form wire:submit="save" class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="form-label">{{ __('Name') }} *</label>
                <input wire:model="form.name" class="form-input">
                @error('form.name') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="form-label">{{ __('Email') }} *</label>
                <input wire:model="form.email" type="email" class="form-input" dir="ltr">
                @error('form.email') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="form-label">{{ __('Phone') }}</label>
                <input wire:model="form.phone" class="form-input" dir="ltr">
            </div>
            <div>
                <label class="form-label">{{ __('Password') }} {{ $editingId ? '' : '*' }}</label>
                <input wire:model="form.password" type="password" autocomplete="new-password" class="form-input" dir="ltr" placeholder="{{ $editingId ? __('Leave blank to keep current') : '' }}">
                @error('form.password') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="form-label">{{ __('Role') }} *</label>
                <select wire:model="form.role" class="form-input">
                    @foreach ($roles as $role)
                        <option value="{{ $role }}">{{ __('role.'.$role) }}</option>
                    @endforeach
                </select>
                @error('form.role') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="form-label">{{ __('Language') }}</label>
                <select wire:model="form.locale" class="form-input">
                    <option value="ar">العربية</option>
                    <option value="en">English</option>
                </select>
            </div>
            <div class="sm:col-span-2">
                <label class="form-label">{{ __('Assigned branches') }}</label>
                <div class="flex flex-wrap gap-3 rounded-lg border border-slate-200 p-3">
                    @foreach ($branches as $b)
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" wire:model="form.branch_ids" value="{{ $b->id }}" class="rounded border-slate-300 text-brand-600"> {{ $b->displayName() }}
                        </label>
                    @endforeach
                </div>
                @error('form.branch_ids') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="form-label">{{ __('Default branch') }}</label>
                <select wire:model="form.default_branch_id" class="form-input">
                    <option value="">—</option>
                    @foreach ($branches as $b)
                        <option value="{{ $b->id }}">{{ $b->displayName() }}</option>
                    @endforeach
                </select>
            </div>
            <label class="flex items-center gap-2 self-end text-sm">
                <input type="checkbox" wire:model="form.is_active" class="rounded border-slate-300 text-brand-600"> {{ __('Active') }}
            </label>
            <div class="flex justify-end gap-2 sm:col-span-2">
                <button type="button" class="btn-secondary" @click="open = false">{{ __('Cancel') }}</button>
                <button type="submit" class="btn-primary">{{ __('Save') }}</button>
            </div>
        </form>
    </x-modal>
</div>
