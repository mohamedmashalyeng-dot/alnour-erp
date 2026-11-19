<?php

namespace App\Modules\Offline\Http;

use App\Http\Controllers\Controller;
use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Offline\Services\OfflineSyncService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Offline POS endpoints. Authentication = normal session + CSRF token; the device is identified by an
 * encrypted, HttpOnly, SameSite=Strict cookie that page scripts cannot read (no secret in IndexedDB).
 */
class OfflinePosController extends Controller
{
    public function __construct(private OfflineSyncService $sync) {}

    public function page(Request $request, BranchAccess $access)
    {
        Gate::authorize('sales.create');

        return view('pos.offline', [
            'branches' => $access->allowedBranches(),
            'registered' => $this->deviceOrNull($request) !== null,
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'branch_id' => ['required', 'integer']]);
        [$device, $secret] = $this->sync->registerDevice($data['name'], (int) $data['branch_id'], $request->userAgent());

        return response()->json(['device' => ['uuid' => $device->uuid, 'name' => $device->name, 'branch_id' => $device->branch_id]])
            ->cookie(OfflineSyncService::COOKIE, $device->uuid.'|'.$secret, 60 * 24 * 365, '/', null, $request->isSecure(), true, false, 'strict');
    }

    public function bootstrap(Request $request): JsonResponse
    {
        $device = $this->requireDevice($request);

        return response()->json($this->sync->bootstrap($device, $request->user()))->header('Cache-Control', 'no-store');
    }

    public function sync(Request $request): JsonResponse
    {
        $device = $this->requireDevice($request);
        $data = $request->validate(['sales' => ['required', 'array', 'max:50']]);

        return response()->json(['results' => $this->sync->sync($device, $request->user(), $data['sales'])])->header('Cache-Control', 'no-store');
    }

    public function ping(): JsonResponse
    {
        return response()->json(['ok' => true, 'time' => now()->toIso8601String()])->header('Cache-Control', 'no-store');
    }

    private function requireDevice(Request $request)
    {
        try {
            return $this->sync->device($request->cookie(OfflineSyncService::COOKIE), $request->user());
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }
    }

    private function deviceOrNull(Request $request)
    {
        try {
            return $this->sync->device($request->cookie(OfflineSyncService::COOKIE), $request->user());
        } catch (AuthorizationException) {
            return null;
        }
    }
}
