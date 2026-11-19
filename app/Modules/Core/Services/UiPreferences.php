<?php

namespace App\Modules\Core\Services;

use App\Models\User;
use App\Modules\Core\Support\Navigation;
use Illuminate\Validation\ValidationException;

/**
 * Writes a user's own interface preferences. A user can only ever change their own, so the
 * caller passes the authenticated user; nothing here grants or removes access to a page.
 */
class UiPreferences
{
    /**
     * Hide or show menu pages.
     *
     * @param  list<string>  $pages  route names from Navigation::keys()
     * @return list<string> the user's hidden pages after the change
     */
    public function setPagesHidden(User $user, array $pages, bool $hidden): array
    {
        $unknown = array_diff($pages, Navigation::keys());
        if ($pages === [] || $unknown !== []) {
            throw ValidationException::withMessages(['pages' => __('Unknown page.')]);
        }

        $current = Navigation::hiddenFor($user);
        $next = $hidden
            ? array_values(array_unique([...$current, ...$pages]))
            : array_values(array_diff($current, $pages));

        $this->save($user, 'hidden_pages', $next);

        return $next;
    }

    public function showAllPages(User $user): void
    {
        $this->save($user, 'hidden_pages', []);
    }

    private function save(User $user, string $key, mixed $value): void
    {
        $prefs = is_array($user->ui_preferences) ? $user->ui_preferences : [];
        $prefs[$key] = $value;
        $user->forceFill(['ui_preferences' => $prefs])->save();
    }
}
