<?php

namespace App\Modules\Branches\Services;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Server-side branch isolation. Every branch-scoped query and action must pass through here.
 *
 * The "current branch" is a UI convenience stored in the session; it is always re-validated
 * against the user's assignments and never trusted on its own.
 */
class BranchAccess
{
    public const SESSION_KEY = 'current_branch_id';

    /** Value of the "all branches" selection (combined view). */
    public const ALL = 0;

    /** @var array<int, list<int>> */
    private array $cache = [];

    /** @return list<int> */
    public function allowedIds(?User $user = null): array
    {
        $user ??= auth()->user();
        if (! $user) {
            return [];
        }

        return $this->cache[$user->id] ??= $user->canAccessAllBranches()
            ? Branch::query()->pluck('id')->map(fn ($id) => (int) $id)->all()
            : $user->branches()->pluck('branches.id')->map(fn ($id) => (int) $id)->all();
    }

    public function flush(): void
    {
        $this->cache = [];
    }

    /** @return Collection<int, Branch> */
    public function allowedBranches(?User $user = null, bool $activeOnly = true): Collection
    {
        return Branch::query()
            ->whereIn('id', $this->allowedIds($user))
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->orderBy('code')
            ->get();
    }

    public function canAccess(int|Branch|null $branch, ?User $user = null): bool
    {
        if ($branch === null) {
            return false;
        }
        $id = $branch instanceof Branch ? $branch->id : $branch;

        return in_array($id, $this->allowedIds($user), true);
    }

    /**
     * @throws AuthorizationException
     */
    public function authorize(int|Branch|null $branch, ?User $user = null): void
    {
        if (! $this->canAccess($branch, $user)) {
            throw new AuthorizationException(__('You are not authorized to access this branch.'));
        }
    }

    /**
     * Authorizes a branch AND requires it to be active (for operations that change stock or money).
     */
    public function authorizeOperational(int $branchId, ?User $user = null): Branch
    {
        $this->authorize($branchId, $user);
        $branch = Branch::findOrFail($branchId);
        if (! $branch->is_active) {
            throw new AuthorizationException(__('This branch is inactive.'));
        }

        return $branch;
    }

    /**
     * The branch currently selected in the UI, or ALL (0) for the combined view.
     */
    public function current(?User $user = null): int
    {
        $user ??= auth()->user();
        $allowed = $this->allowedIds($user);
        $selected = session(self::SESSION_KEY);

        if ($selected === self::ALL && $user?->canAccessAllBranches()) {
            return self::ALL;
        }
        if ($selected !== null && in_array((int) $selected, $allowed, true)) {
            return (int) $selected;
        }
        if ($user?->default_branch_id && in_array($user->default_branch_id, $allowed, true)) {
            return $user->default_branch_id;
        }

        return $allowed[0] ?? -1;
    }

    /**
     * The current branch for operations that need a concrete branch (never ALL).
     */
    public function currentOperational(?User $user = null): ?int
    {
        $current = $this->current($user);
        if ($current > 0) {
            return $current;
        }
        $user ??= auth()->user();
        $allowed = $this->allowedIds($user);
        if ($user?->default_branch_id && in_array($user->default_branch_id, $allowed, true)) {
            return $user->default_branch_id;
        }

        return $this->allowedBranches($user)->first()?->id;
    }

    public function select(int $branchId, ?User $user = null): void
    {
        $user ??= auth()->user();
        if ($branchId === self::ALL) {
            if (! $user->canAccessAllBranches()) {
                throw new AuthorizationException(__('You are not authorized to access this branch.'));
            }
        } else {
            $this->authorize($branchId, $user);
        }
        session([self::SESSION_KEY => $branchId]);
    }

    /**
     * Restrict a query to the given branch (or all allowed branches when $branchId is ALL/null).
     */
    public function scope(Builder $query, ?int $branchId = null, string $column = 'branch_id', ?User $user = null): Builder
    {
        if ($branchId !== null && $branchId !== self::ALL) {
            $this->authorize($branchId, $user);

            return $query->where($column, $branchId);
        }

        return $query->whereIn($column, $this->allowedIds($user));
    }
}
