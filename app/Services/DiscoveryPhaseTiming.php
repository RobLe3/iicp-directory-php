<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Services;

/**
 * Request-local, content-free timing evidence for directory discovery.
 *
 * Only fixed phase names and numeric durations leave this object. It must not
 * receive node identifiers, endpoints, query text, task content, or SQL.
 */
final class DiscoveryPhaseTiming
{
    /** @var array<string,string> */
    private const METRICS = [
        'cache' => 'iicp_cache',
        'database_hydration' => 'iicp_db',
        'scoring' => 'iicp_score',
        'operator_enrichment' => 'iicp_operator',
        'response_build' => 'iicp_response',
        'total' => 'iicp_total',
    ];

    /** @var array<string,float> */
    private array $durations = [];

    /** @template T @param callable():T $operation @return T */
    public function measure(string $phase, callable $operation): mixed
    {
        if (! array_key_exists($phase, self::METRICS)) {
            throw new \InvalidArgumentException('Unknown discovery timing phase.');
        }
        $started = hrtime(true);
        try {
            return $operation();
        } finally {
            $this->durations[$phase] = round((hrtime(true) - $started) / 1_000_000, 3);
        }
    }

    public function set(string $phase, float $milliseconds): void
    {
        if (! array_key_exists($phase, self::METRICS)) {
            return;
        }
        $this->durations[$phase] = round(max(0.0, $milliseconds), 3);
    }

    /** @return array<string,float> */
    public function values(): array
    {
        return $this->durations;
    }

    public function serverTiming(): string
    {
        $parts = [];
        foreach (self::METRICS as $phase => $metric) {
            if (array_key_exists($phase, $this->durations)) {
                $parts[] = sprintf('%s;dur=%.3f', $metric, $this->durations[$phase]);
            }
        }

        return implode(', ', $parts);
    }
}
