<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProbeController extends Controller
{
    // Private/internal ranges — block to prevent SSRF. Covers both families;
    // ipInCidr() compares by address family so v4 entries never match a v6 IP
    // and vice-versa.
    private const BLOCKED_CIDR = [
        // IPv4
        '127.0.0.0/8',
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '169.254.0.0/16',
        // IPv6
        '::1/128',          // loopback
        '::/128',           // unspecified
        'fe80::/10',        // link-local
        'fc00::/7',         // unique-local (ULA)
        'fec0::/10',        // deprecated site-local
        '::ffff:0:0/96',    // IPv4-mapped — blocks ::ffff:10.0.0.1 style bypass
        '64:ff9b::/96',     // NAT64 well-known prefix
    ];

    public function check(Request $request): JsonResponse
    {
        $request->validate([
            'host' => ['required', 'string', 'max:253'],
            'port' => ['required', 'integer', 'min:1024', 'max:65535'],
        ]);

        $host = $request->input('host');
        $port = (int) $request->input('port');

        // Resolve hostname to IP once. Using this single resolved IP for both the SSRF
        // guard and the actual curl request closes the DNS rebinding TOCTOU window —
        // curl never re-resolves the hostname; it connects to the IP we already checked.
        //
        // Fail-closed on any internal exception (e.g., PHP-FPM opcache stale class on
        // shared hosting — #276): return private_address 422 rather than expose a 500.
        try {
            $resolvedIp = $this->resolveToIp($host);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'reachable' => false,
                'latency_ms' => null,
                'error' => 'private_address',
            ], 422);
        }

        if ($resolvedIp === null) {
            return response()->json([
                'reachable' => false,
                'latency_ms' => null,
                'error' => 'unresolvable_host',
            ], 422);
        }

        try {
            $blocked = $this->isBlockedIp($resolvedIp);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'reachable' => false,
                'latency_ms' => null,
                'error' => 'private_address',
            ], 422);
        }

        if ($blocked) {
            return response()->json([
                'reachable' => false,
                'latency_ms' => null,
                'error' => 'private_address',
            ], 422);
        }

        $start = microtime(true);
        [$reachable, $error, $primaryIp] = $this->probe($resolvedIp, $host, $port);
        $latencyMs = (int) round((microtime(true) - $start) * 1000);

        // Defense in depth: if curl connected to a different IP than the one we checked
        // (e.g., via redirect or an unexpected rebind in the connection pool), block it.
        if ($primaryIp !== null && $primaryIp !== $resolvedIp && $this->isBlockedIp($primaryIp)) {
            return response()->json([
                'reachable' => false,
                'latency_ms' => null,
                'error' => 'private_address',
            ], 422);
        }

        return response()->json([
            'reachable' => $reachable,
            'latency_ms' => $reachable ? $latencyMs : null,
            'error' => $error,
        ]);
    }

    /**
     * Resolve a hostname or IP string to a single IPv4 address.
     * Returns null if the host cannot be resolved or is not IPv4.
     */
    protected function resolveToIp(string $host): ?string
    {
        $lower = strtolower(trim($host));

        // Explicit loopback checks before resolution to avoid gethostbyname('localhost')
        // returning '127.0.0.1' (which would be caught by CIDR, but early-out is cleaner)
        if ($lower === 'localhost' || $lower === '::1') {
            return '127.0.0.1'; // Return a blocked IP so the caller rejects it correctly
        }

        // Already a valid IPv4 address — return as-is
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $host;
        }

        // IPv6 literal — accept and let isBlockedIp() vet it. IICP nodes behind
        // CGNAT advertise an IPv6 GUA endpoint (e.g. http://[2a0a:...]:9484), so
        // the directory must be able to probe IPv6 reachability. SSRF-dangerous
        // ranges (loopback, link-local, ULA, IPv4-mapped, NAT64) are blocked in
        // BLOCKED_CIDR.
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return $host;
        }

        // Hostname — resolve to IPv4 once
        $resolved = gethostbyname($host);
        if ($resolved === $host) {
            // gethostbyname returns the input unchanged when resolution fails
            return null;
        }

        if (filter_var($resolved, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }

        return $resolved;
    }

    /**
     * Check if an already-resolved IPv4 address is in a blocked range.
     * Only call this with an IP, not a hostname.
     */
    protected function isBlockedIp(string $ip): bool
    {
        $lower = strtolower(trim($ip));
        if ($lower === '::1') {
            return true;
        }

        foreach (self::BLOCKED_CIDR as $cidr) {
            if ($this->ipInCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Make the HEAD probe using the pre-resolved IP in the URL.
     * The original hostname is passed as the Host: header for virtual hosting.
     * Returns [reachable, error_code_or_null, primary_ip_or_null].
     */
    protected function probe(string $resolvedIp, string $originalHost, int $port): array
    {
        // IPv6 literals must be bracketed in a URL authority (RFC 3986 §3.2.2).
        $hostPart = str_contains($resolvedIp, ':') ? "[{$resolvedIp}]" : $resolvedIp;
        $url = "http://{$hostPart}:{$port}/iicp/health";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_NOBODY => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'iicp-directory-probe/1.0',
            CURLOPT_HTTPHEADER => ["Host: {$originalHost}"],
        ]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $primaryIp = curl_getinfo($ch, CURLINFO_PRIMARY_IP) ?: null;
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError !== '') {
            $errorCode = str_contains($curlError, 'timed out') ? 'timeout' : 'connection_refused';

            return [false, $errorCode, $primaryIp];
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            return [true, null, $primaryIp];
        }

        return [false, 'http_'.$httpCode, $primaryIp];
    }

    /**
     * Family-agnostic CIDR membership via binary prefix comparison. inet_pton
     * yields a 4-byte string for IPv4 and 16-byte for IPv6; an IP only matches a
     * CIDR of the same family (lengths must be equal), so a single mixed
     * BLOCKED_CIDR list is safe.
     */
    protected function ipInCidr(string $ip, string $cidr): bool
    {
        [$network, $bitsStr] = array_pad(explode('/', $cidr, 2), 2, '0');
        $bits = (int) $bitsStr;

        $ipBin = @inet_pton($ip);
        $netBin = @inet_pton($network);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }
        if ($bits < 0 || $bits > strlen($ipBin) * 8) {
            return false;
        }

        $fullBytes = intdiv($bits, 8);
        $remBits = $bits % 8;

        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($netBin, 0, $fullBytes)) {
            return false;
        }
        if ($remBits > 0) {
            $mask = (~(0xFF >> $remBits)) & 0xFF;
            if ((ord($ipBin[$fullBytes]) & $mask) !== (ord($netBin[$fullBytes]) & $mask)) {
                return false;
            }
        }

        return true;
    }
}
