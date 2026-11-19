<?php

namespace App\Http\Controllers;

use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Core\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SessionController extends Controller
{
    public function logout(Request $request, AuditLogger $audit): RedirectResponse
    {
        $audit->log('auth.logout', $request->user());
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    public function locale(Request $request, string $locale): RedirectResponse
    {
        abort_unless(in_array($locale, config('erp.locales'), true), 404);
        $request->session()->put('locale', $locale);
        $request->user()?->forceFill(['locale' => $locale])->save();

        return back();
    }

    public function switchBranch(Request $request, BranchAccess $branches): RedirectResponse
    {
        $data = $request->validate(['branch_id' => ['required', 'integer', 'min:0']]);
        $branches->select((int) $data['branch_id']);

        return back();
    }
}
