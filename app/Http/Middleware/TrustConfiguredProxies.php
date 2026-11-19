<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;

/**
 * Trusts X-Forwarded-* headers only from proxies listed in TRUSTED_PROXIES ("*" or comma-separated IPs).
 * Untrusted forwarded headers would let clients spoof the IP used by rate limiting and audit logs.
 */
class TrustConfiguredProxies extends TrustProxies
{
    protected function proxies()
    {
        $value = config('erp.trusted_proxies');
        if (! $value) {
            return null;
        }

        return $value === '*' ? '*' : array_map('trim', explode(',', $value));
    }
}
