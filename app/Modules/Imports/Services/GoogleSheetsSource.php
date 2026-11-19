<?php

namespace App\Modules\Imports\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Reads a Google Sheet as CSV text.
 *
 *  - With a service account configured (GOOGLE_SERVICE_ACCOUNT_JSON → path to the key file, stored
 *    outside the web root), private sheets shared with that service account are read through the
 *    Sheets API using a short-lived OAuth token (read-only scope). The key never leaves the server.
 *  - Without it, only sheets shared as "anyone with the link can view" can be read, via the
 *    official CSV export endpoint.
 * Both paths go through SafeUrlFetcher-equivalent host restrictions.
 */
class GoogleSheetsSource
{
    public function __construct(private SafeUrlFetcher $fetcher) {}

    /** @return array{id:string, gid:?string} */
    public function parse(string $url): array
    {
        if (! preg_match('~^https://docs\.google\.com/spreadsheets/d/([A-Za-z0-9_-]{20,100})~', $url, $m)) {
            throw ValidationException::withMessages(['url' => __('This is not a Google Sheets link.')]);
        }
        preg_match('~[#&?]gid=(\d+)~', $url, $g);

        return ['id' => $m[1], 'gid' => $g[1] ?? null];
    }

    public function csv(string $url): string
    {
        $ref = $this->parse($url);
        if ($this->hasServiceAccount()) {
            return $this->viaApi($ref['id']);
        }
        $export = 'https://docs.google.com/spreadsheets/d/'.$ref['id'].'/export?format=csv'.($ref['gid'] !== null ? '&gid='.$ref['gid'] : '');
        $res = $this->fetcher->fetch($export, acceptTypes: ['text/csv', 'text/plain', 'application/octet-stream']);

        return $res['body'];
    }

    public function hasServiceAccount(): bool
    {
        $path = (string) config('erp.imports.google_service_account');

        return $path !== '' && is_readable($path);
    }

    private function viaApi(string $spreadsheetId): string
    {
        $token = $this->accessToken();
        $this->fetcher->validate('https://sheets.googleapis.com/');
        $meta = Http::withToken($token)->timeout(20)
            ->get("https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}", ['fields' => 'sheets.properties.title']);
        if (! $meta->successful()) {
            throw ValidationException::withMessages(['url' => __('Google refused access to this sheet. Share it with the service account (read-only).')]);
        }
        $title = $meta->json('sheets.0.properties.title') ?? 'Sheet1';
        $values = Http::withToken($token)->timeout(30)
            ->get("https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}/values/".rawurlencode($title), ['valueRenderOption' => 'FORMATTED_VALUE'])
            ->throw()->json('values', []);

        $fh = fopen('php://temp', 'w+');
        foreach ($values as $row) {
            fputcsv($fh, array_map('strval', $row), ',', '"', '\\');
        }
        rewind($fh);

        return (string) stream_get_contents($fh);
    }

    /** OAuth2 JWT-bearer flow for a service account (RS256), cached until shortly before expiry. */
    private function accessToken(): string
    {
        return Cache::remember('google-sheets-token', now()->addMinutes(50), function () {
            $key = json_decode((string) file_get_contents((string) config('erp.imports.google_service_account')), true);
            if (! isset($key['client_email'], $key['private_key'])) {
                throw new RuntimeException('Invalid Google service account file.');
            }
            $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
            $now = time();
            $header = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $claims = $b64(json_encode([
                'iss' => $key['client_email'], 'scope' => 'https://www.googleapis.com/auth/spreadsheets.readonly',
                'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600,
            ]));
            openssl_sign("$header.$claims", $signature, $key['private_key'], OPENSSL_ALGO_SHA256);
            $jwt = "$header.$claims.".$b64($signature);

            return Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt,
            ])->throw()->json('access_token');
        });
    }
}
