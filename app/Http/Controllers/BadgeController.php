<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Http\Controllers;

use App\Models\ConformanceBadge;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * SVG shield badge renderer — visual trust signal for the ecosystem (BADGE-04).
 *
 * WHY SVG shields (not JSON API): shields.io-compatible embed format. Operators paste
 * a single <img> URL into their README and get a live badge. JSON would require
 * downstream rendering code from every consumer.
 *
 * WHY separate from ConformanceController: SRP. ConformanceController handles badge
 * ingestion and verification (mutation + authoritative query). BadgeController is
 * display-only (read + render). Keeping them separate avoids coupling the ingestion
 * path to SVG rendering logic.
 *
 * WHY Cache-Control: max-age=300: badge shields are embedded in GitHub READMEs and
 * wikis, which cache aggressively. 5-minute TTL means a newly expired badge disappears
 * within 5 minutes rather than hours. Shorter TTL would increase directory load without
 * meaningful UX benefit — badge state rarely flips more than once per day.
 *
 * WHY color coded: green (#44cc11) = genesis-verified (directory-signed); yellow (#dfb317)
 * = self-attested (submitter-asserted, not cryptographically verified by directory);
 * grey (#9f9f9f) = expired or unknown. This distinction surfaces the trust level to
 * operators browsing a README without requiring them to query the API.
 *
 * Spec: spec/iicp-recognition.md (BADGE-04). ADR: ADR-013 (conformance recognition).
 */
class BadgeController extends Controller
{
    private const VALID_TIERS = [
        'iicp:core:v1',
        'iicp:mesh:v1',
        'iicp:sdk:v1',
        'iicp:federated:v1',
        'iicp:full:v1',
    ];

    /**
     * GET /api/v1/badge/{tier}?did=
     * Returns an SVG shield reflecting current badge status (BADGE-04, expiry-aware).
     */
    public function shield(Request $request, string $tier): Response
    {
        $did = $request->query('did');

        if (! $did || ! in_array($tier, self::VALID_TIERS, true)) {
            return $this->svgShield($tier ?: 'iicp', 'unknown', '#9f9f9f');
        }

        $badge = ConformanceBadge::where('subject_did', $did)
            ->where('tier', $tier)
            ->orderBy('expires_at', 'desc')
            ->first();

        if (! $badge) {
            return $this->svgShield($tier, 'not found', '#e05d44');
        }

        if ($badge->status !== 'active' || Carbon::parse($badge->expires_at)->isPast()) {
            $label = $badge->verification_mode === 'genesis-verified' ? 'expired ✓' : 'expired (self)';

            return $this->svgShield($tier, $label, '#9f9f9f');
        }

        $label = $badge->verification_mode === 'genesis-verified' ? 'compliant ✓' : 'self-attested';
        $color = $badge->verification_mode === 'genesis-verified' ? '#44cc11' : '#dfb317';

        return $this->svgShield($tier, $label, $color);
    }

    private function svgShield(string $left, string $right, string $rightColor): Response
    {
        // XML-escape text content — defense against SVG injection if $tier or labels ever
        // carry untrusted input (e.g. when ?did= is absent, $tier is URL-user-supplied).
        $left = htmlspecialchars($left, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $right = htmlspecialchars($right, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $lw = max(60, strlen($left) * 7 + 10);
        $rw = max(80, strlen($right) * 7 + 10);
        $tw = $lw + $rw;
        $lx = (int) ($lw / 2 + 1);
        $rx = (int) ($lw + $rw / 2 - 1);

        $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="{$tw}" height="20">
  <linearGradient id="s" x2="0" y2="100%"><stop offset="0" stop-color="#bbb" stop-opacity=".1"/><stop offset="1" stop-opacity=".1"/></linearGradient>
  <clipPath id="r"><rect width="{$tw}" height="20" rx="3" fill="#fff"/></clipPath>
  <g clip-path="url(#r)">
    <rect width="{$lw}" height="20" fill="#555"/>
    <rect x="{$lw}" width="{$rw}" height="20" fill="{$rightColor}"/>
    <rect width="{$tw}" height="20" fill="url(#s)"/>
  </g>
  <g fill="#fff" text-anchor="middle" font-family="DejaVu Sans,Verdana,Geneva,sans-serif" font-size="110">
    <text x="{$lx}0" y="150" fill="#010101" fill-opacity=".3" transform="scale(.1)" textLength="{$lw}0">{$left}</text>
    <text x="{$lx}0" y="140" transform="scale(.1)" textLength="{$lw}0">{$left}</text>
    <text x="{$rx}0" y="150" fill="#010101" fill-opacity=".3" transform="scale(.1)" textLength="{$rw}0">{$right}</text>
    <text x="{$rx}0" y="140" transform="scale(.1)" textLength="{$rw}0">{$right}</text>
  </g>
</svg>
SVG;

        return response($svg, 200, ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'max-age=300']);
    }
}
