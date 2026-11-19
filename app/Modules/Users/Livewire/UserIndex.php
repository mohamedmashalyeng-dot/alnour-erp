<?php

namespace App\Modules\Users\Livewire;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Core\Permissions;
use App\Modules\Core\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Users')]
class UserIndex extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    public bool $showForm = false;

    #[Locked]
    public ?int $editingId = null;

    public array $form = [];

    public function mount(): void
    {
        $this->authorize('users.view');
        $this->resetForm();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function create(): void
    {
        $this->authorize('users.manage');
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorize('users.manage');
        $user = User::with('roles', 'branches')->findOrFail($id);
        $this->guardSuperAdmin($user);
        $this->editingId = $user->id;
        $this->form = [
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'password' => '',
            'role' => $user->roles->first()?->name ?? '',
            'branch_ids' => $user->branches->pluck('id')->map(fn ($id) => (string) $id)->all(),
            'default_branch_id' => (string) $user->default_branch_id,
            'locale' => $user->locale,
            'is_active' => $user->is_active,
        ];
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(AuditLogger $audit, BranchAccess $access): void
    {
        $this->authorize('users.manage');
        $assignableRoles = $this->assignableRoles();

        $data = $this->validate([
            'form.name' => ['required', 'string', 'max:255'],
            'form.email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->editingId)],
            'form.phone' => ['nullable', 'string', 'max:32'],
            'form.password' => [$this->editingId ? 'nullable' : 'required', 'string', Password::defaults()],
            'form.role' => ['required', Rule::in($assignableRoles)],
            'form.branch_ids' => ['array'],
            'form.branch_ids.*' => ['integer', 'exists:branches,id'],
            'form.default_branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'form.locale' => ['required', Rule::in(config('erp.locales'))],
            'form.is_active' => ['boolean'],
        ])['form'];

        $branchIds = array_map('intval', $data['branch_ids'] ?? []);
        $default = $data['default_branch_id'] ? (int) $data['default_branch_id'] : null;
        if ($default && ! in_array($default, $branchIds, true)) {
            $branchIds[] = $default;
        }
        // A user can only grant access to branches they can access themselves.
        foreach ($branchIds as $id) {
            $access->authorize($id);
        }

        DB::transaction(function () use ($data, $branchIds, $default, $audit) {
            $attributes = [
                'name' => $data['name'],
                'email' => mb_strtolower($data['email']),
                'phone' => $data['phone'] ?: null,
                'locale' => $data['locale'],
                'is_active' => (bool) $data['is_active'],
                'default_branch_id' => $default,
            ];
            if (! empty($data['password'])) {
                $attributes['password'] = $data['password'];
            }

            if ($this->editingId) {
                $user = User::findOrFail($this->editingId);
                $this->guardSuperAdmin($user);
                if ($user->id === auth()->id() && ! $attributes['is_active']) {
                    abort(422, __('You cannot deactivate your own account.'));
                }
                $original = $user->getAttributes();
                $user->update($attributes);
                $audit->updated($user, 'user', $original);
            } else {
                $user = User::create($attributes);
                $audit->created($user, 'user');
            }

            $oldRoles = $user->getRoleNames()->all();
            $oldBranches = $user->branches()->pluck('branches.id')->all();
            $user->syncRoles([$data['role']]);
            $user->branches()->sync($branchIds);
            $audit->log('user.access_changed', $user, ['roles' => $oldRoles, 'branches' => $oldBranches], ['roles' => [$data['role']], 'branches' => $branchIds]);
        });

        $access->flush();
        $this->showForm = false;
        $this->dispatch('toast', message: __('Saved successfully.'));
    }

    /** @return list<string> */
    private function assignableRoles(): array
    {
        $roles = array_keys(Permissions::ROLES);
        // Only a super admin may create admins.
        if (! auth()->user()->isSuperAdmin()) {
            $roles = array_values(array_diff($roles, ['admin']));
        }

        return $roles;
    }

    private function guardSuperAdmin(User $user): void
    {
        if ($user->isSuperAdmin() && ! auth()->user()->isSuperAdmin()) {
            abort(403);
        }
    }

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->form = ['name' => '', 'email' => '', 'phone' => '', 'password' => '', 'role' => 'cashier', 'branch_ids' => [], 'default_branch_id' => '', 'locale' => 'ar', 'is_active' => true];
        $this->resetValidation();
    }

    public function render(BranchAccess $access)
    {
        $allowed = $access->allowedIds();
        $users = User::query()
            ->with(['roles', 'branches:id,code,name,name_en'])
            ->when(! auth()->user()->canAccessAllBranches(), fn ($q) => $q->whereHas('branches', fn ($b) => $b->whereIn('branches.id', $allowed)))
            ->when($this->search !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$this->search}%")->orWhere('email', 'like', "%{$this->search}%")))
            ->orderBy('name')
            ->paginate(20);

        return view('livewire.users.index', [
            'users' => $users,
            'branches' => Branch::whereIn('id', $allowed)->orderBy('code')->get(),
            'roles' => $this->assignableRoles(),
        ]);
    }
}
