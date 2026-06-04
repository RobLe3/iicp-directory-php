<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Services;

use Illuminate\Http\Request;

/**
 * Lightweight W3C traceparent + OTLP/JSON span emitter — ADR-014.
 *
 * No-op when OTEL_EXPORTER_OTLP_ENDPOINT is absent (default on shared hosting).
 * Emits OTLP/JSON spans when the endpoint is configured.
 * Parses incoming W3C traceparent header to maintain distributed trace context.
 *
 * Directory spans: iicp.directory.discover (TRACE-11), iicp.directory.heartbeat (TRACE-12)
 */

/**
 * Lightweight span value object. In no-op mode all methods are zero-cost.
 */
class OtelSpan
{
    private bool $ended = false;

    /** @var array<string, mixed> */
    private array $attributes = [];

    public function __construct(
        private readonly ?string $traceId,
        private readonly ?string $spanId,
        private readonly ?string $parentSpanId,
        private readonly string $name,
        private readonly int $startNs,
        private readonly bool $noop,
    ) {}

    public function setAttribute(string $key, mixed $value): self
    {
        if (! $this->noop) {
            $this->attributes[$key] = $value;
        }

        return $this;
    }

    public function end(): void
    {
        if ($this->noop || $this->ended) {
            return;
        }
        $this->ended = true;
        OtelTracer::export($this->buildPayload(hrtime(true)));
    }

    private function buildPayload(int $endNs): string
    {
        $attrs = [];
        foreach ($this->attributes as $key => $value) {
            $attrs[] = [
                'key' => $key,
                'value' => match (true) {
                    is_int($value) => ['intValue' => (string) $value],
                    is_float($value) => ['doubleValue' => $value],
                    is_bool($value) => ['boolValue' => $value],
                    default => ['stringValue' => (string) $value],
                },
            ];
        }

        $span = [
            'traceId' => $this->traceId,
            'spanId' => $this->spanId,
            'name' => $this->name,
            'kind' => 2, // SPAN_KIND_SERVER
            'startTimeUnixNano' => (string) $this->startNs,
            'endTimeUnixNano' => (string) $endNs,
            'attributes' => $attrs,
            'status' => ['code' => 1], // STATUS_CODE_OK
        ];

        if ($this->parentSpanId !== null) {
            $span['parentSpanId'] = $this->parentSpanId;
        }

        return (string) json_encode([
            'resourceSpans' => [[
                'resource' => ['attributes' => [
                    ['key' => 'service.name', 'value' => ['stringValue' => OtelTracer::getServiceName()]],
                    ['key' => 'service.version', 'value' => ['stringValue' => (string) config('app.iicp_version', 'unknown')]],
                ]],
                'scopeSpans' => [[
                    'scope' => ['name' => 'iicp.directory', 'version' => '1.0'],
                    'spans' => [$span],
                ]],
            ]],
        ], JSON_THROW_ON_ERROR);
    }
}

/**
 * Static tracer factory. Thread-safe within a single PHP request lifecycle.
 */
class OtelTracer
{
    private static bool $initialised = false;

    private static bool $enabled = false;

    private static string $endpoint = '';

    private static string $serviceName = 'iicp-directory';

    public static function init(): void
    {
        if (self::$initialised) {
            return;
        }
        self::$initialised = true;

        $endpoint = (string) (config('otel.endpoint') ?? '');
        if ($endpoint !== '') {
            self::$enabled = true;
            self::$endpoint = rtrim($endpoint, '/').'/v1/traces';
            self::$serviceName = (string) (config('otel.service_name') ?? 'iicp-directory');
        }
    }

    /**
     * Start a server span for the given request, inheriting traceparent if present.
     */
    public static function startSpan(Request $request, string $spanName): OtelSpan
    {
        self::init();

        if (! self::$enabled) {
            return new OtelSpan(null, null, null, $spanName, hrtime(true), true);
        }

        [$traceId, $parentSpanId] = self::parseTraceparent(
            (string) ($request->header('traceparent') ?? '')
        );
        $traceId ??= bin2hex(random_bytes(16));
        $spanId = bin2hex(random_bytes(8));

        return new OtelSpan($traceId, $spanId, $parentSpanId, $spanName, hrtime(true), false);
    }

    /**
     * Send a pre-built OTLP/JSON payload. Errors are suppressed — OTel must never break the app.
     */
    public static function export(string $payload): void
    {
        if (! self::$enabled || self::$endpoint === '') {
            return;
        }

        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
            'content' => $payload,
            'timeout' => 0.5,
            'ignore_errors' => true,
        ]]);

        @file_get_contents(self::$endpoint, false, $ctx);
    }

    /**
     * Parse a W3C traceparent header into [traceId, parentSpanId].
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function parseTraceparent(string $header): array
    {
        if (! preg_match('/^00-([0-9a-f]{32})-([0-9a-f]{16})-[0-9a-f]{2}$/', $header, $m)) {
            return [null, null];
        }

        return [$m[1], $m[2]];
    }

    public static function isEnabled(): bool
    {
        self::init();

        return self::$enabled;
    }

    public static function getServiceName(): string
    {
        return self::$serviceName;
    }

    /** Reset static state — for testing only. */
    public static function reset(): void
    {
        self::$initialised = false;
        self::$enabled = false;
        self::$endpoint = '';
        self::$serviceName = 'iicp-directory';
    }
}
