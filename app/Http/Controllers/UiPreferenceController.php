<?php

namespace App\Http\Controllers;

use App\Modules\Core\Services\UiPreferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class UiPreferenceController extends Controller
{
    /** Hide or show one or more menu pages for the signed-in user. */
    public function pages(Request $request, UiPreferences $prefs): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'pages' => ['required', 'array', 'min:1', 'max:100'],
            'pages.*' => ['required', 'string', 'max:100'],
            'hidden' => ['required', 'boolean'],
        ]);

        $hidden = $prefs->setPagesHidden($request->user(), array_values($data['pages']), (bool) $data['hidden']);

        return $request->expectsJson() ? response()->json(['hidden' => $hidden]) : back();
    }

    public function showAll(Request $request, UiPreferences $prefs): JsonResponse|RedirectResponse
    {
        $prefs->showAllPages($request->user());

        return $request->expectsJson()
            ? response()->json(['hidden' => []])
            : back()->with('success', __('All pages are visible again.'));
    }
}
