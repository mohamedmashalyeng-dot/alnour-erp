<?php

namespace App\Modules\Imports\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * Downloads an external resource with SSRF protection:
 *   - https only, no credentials in the URL, default port only;
 *   - host must be on the configured allow-list (Google Sheets hosts by default);
 *   - every resolved IP must be public (no private, loopback, link-local or reserved ranges);
 *   - the connection is pinned to the vetted IP (defeats DNS rebinding);
 *   - redirects are followed manually and each hop is re-validated;
 *   - size and time limits.
 */
class SafeUrlFetcher
{
    /** @var callable(string): list<string>|null  test seam for DNS resolution */
    public static $resolver = null;

    public function fetch(string $url, int $maxBytes = 10_485_760, array $acceptTypes = []): array
    {
        $hops = 0;
        while (true) {
            [$host, $ip] = $this->validate($url);
            $response = Http::timeout(20)->connectTimeout(5)
                ->withOptions([
                    'allow_redirects' => false,
                    'curl' => [CURLOPT_RESOLVE => ["$host:443:$ip"]],
                    'stream' => false,
                ])
                ->withHeaders(['User-Agent' => 'EyewearERP-Importer/1.0'])
                ->get($url);

            if (in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                if (++$hops > 3 || ! ($next = $response->header('Location'))) {
                    throw ValidationException::withMessages(['url' => __('Too many redirects.')]);
                }
                $url = str_starts_with($next, 'http') ? $next : 'https://'.$host.$next;

                continue;
            }
            if (! $response->successful()) {
                throw ValidationException::withMessages(['url' => __('The remote server returned HTTP :status.', ['status' => $response->status()])]);
            }
            $body = $response->body();
            if (strlen($body) > $maxBytes) {
                throw ValidationException::withMessages(['url' => __('The remote file is too large.')]);
            }
            $type = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
            if ($acceptTypes && ! in_array($type, $acceptTypes, true)) {
                throw ValidationException::withMessages(['url' => __('Unexpected content type: :type', ['type' => $type ?: '-'])]);
            }

            return ['body' => $body, 'type' => $type];
        }
    }

    /**
     * @return array{0:string,1:string} host and the vetted public IP
     */
    public function validate(string $url): array
    {
        $parts = parse_url($url);
        if (! $parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            throw ValidationException::withMessages(['url' => __('Only plain https:// links are allowed.')]);
        }
        $host = strtolower(rtrim($parts['host'], '.'));
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP)) {
            throw ValidationException::withMessages(['url' => __('IP addresses are not allowed; use the official host name.')]);
        }
        if (! $this->hostAllowed($host)) {
            throw ValidationException::withMessages(['url' => __('This host is not on the list of allowed spreadsheet sources.')]);
        }
        $ips = $this->resolve($host);
        if ($ips === []) {
            throw ValidationException::withMessages(['url' => __('The host name could not be resolved.')]);
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw ValidationException::withMessages(['url' => __('The link points to a private or reserved network address.')]);
            }
        }

        return [$host, $ips[0]];
    }

    public function hostAllowed(string $host): bool
    {
        foreach ((array) config('erp.imports.allowed_hosts', []) as $pattern) {
            $pattern = strtolower($pattern);
            if ($host === $pattern || (str_starts_with($pattern, '*.') && str_ends_with($host, substr($pattern, 1)))) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function resolve(string $host): array
    {
        if (self::$resolver) {
            return (self::$resolver)($host);
        }
        $ips = [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $r) {
            $ips[] = $r['ip'] ?? $r['ipv6'] ?? null;
        }

        return array_values(array_filter($ips));
    }
}
