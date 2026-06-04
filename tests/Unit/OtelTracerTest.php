<?php

namespace Tests\Unit;

use App\Services\OtelSpan;
use App\Services\OtelTracer;
use Illuminate\Http\Request;
use Tests\TestCase;

class OtelTracerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        OtelTracer::reset();
    }

    public function test_tracer_disabled_when_no_endpoint_configured(): void
    {
        $this->assertFalse(OtelTracer::isEnabled());
    }

    public function test_startspan_returns_otelspan_in_noop_mode(): void
    {
        $request = Request::create('/v1/discover', 'GET');
        $span = OtelTracer::startSpan($request, 'iicp.directory.discover');
        $this->assertInstanceOf(OtelSpan::class, $span);
    }

    public function test_setattribute_is_chainable_in_noop_mode(): void
    {
        $request = Request::create('/v1/discover', 'GET');
        $span = OtelTracer::startSpan($request, 'iicp.directory.discover');
        $result = $span->setAttribute('iicp.intent', 'urn:iicp:intent:llm:chat:v1')
            ->setAttribute('iicp.discover.count', 5)
            ->setAttribute('iicp.discover.query_ms', 12.5)
            ->setAttribute('iicp.heartbeat.available', true);
        $this->assertInstanceOf(OtelSpan::class, $result);
    }

    public function test_end_is_safe_to_call_multiple_times_in_noop_mode(): void
    {
        $request = Request::create('/v1/discover', 'GET');
        $span = OtelTracer::startSpan($request, 'iicp.directory.discover');
        $span->end();
        $span->end();
        $this->assertTrue(true); // no exception thrown
    }

    public function test_parsetraceparent_returns_nulls_for_invalid_header(): void
    {
        [$traceId, $parentSpanId] = OtelTracer::parseTraceparent('not-a-traceparent');
        $this->assertNull($traceId);
        $this->assertNull($parentSpanId);
    }

    public function test_parsetraceparent_returns_nulls_for_empty_string(): void
    {
        [$traceId, $parentSpanId] = OtelTracer::parseTraceparent('');
        $this->assertNull($traceId);
        $this->assertNull($parentSpanId);
    }

    public function test_parsetraceparent_extracts_ids_from_valid_w3c_header(): void
    {
        $header = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';
        [$traceId, $parentSpanId] = OtelTracer::parseTraceparent($header);
        $this->assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $traceId);
        $this->assertSame('00f067aa0ba902b7', $parentSpanId);
    }

    public function test_parsetraceparent_rejects_wrong_version_prefix(): void
    {
        $header = '01-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';
        [$traceId, $parentSpanId] = OtelTracer::parseTraceparent($header);
        $this->assertNull($traceId);
        $this->assertNull($parentSpanId);
    }

    public function test_parsetraceparent_rejects_truncated_header(): void
    {
        $header = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7';
        [$traceId, $parentSpanId] = OtelTracer::parseTraceparent($header);
        $this->assertNull($traceId);
        $this->assertNull($parentSpanId);
    }

    public function test_startspan_with_traceparent_header_uses_noop_when_disabled(): void
    {
        $request = Request::create('/v1/discover', 'GET', [], [], [], [
            'HTTP_TRACEPARENT' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
        ]);
        $span = OtelTracer::startSpan($request, 'iicp.directory.discover');
        $this->assertInstanceOf(OtelSpan::class, $span);
        $span->end();
    }

    public function test_getservicename_returns_default_when_not_configured(): void
    {
        OtelTracer::init();
        $this->assertSame('iicp-directory', OtelTracer::getServiceName());
    }

    /** TRACE-14: credits.award span is created and chainable in no-op mode. */
    public function test_credits_award_span_created_in_noop_mode(): void
    {
        $request = Request::create('/v1/credits/award', 'POST');
        $span = OtelTracer::startSpan($request, 'iicp.directory.credits.award');
        $this->assertInstanceOf(OtelSpan::class, $span);
        $result = $span->setAttribute('iicp.node_id', 'test-node-id')
            ->setAttribute('iicp.credit_amount', 1.5)
            ->setAttribute('iicp.tokens_used', 1500)
            ->setAttribute('iicp.new_balance', 10.5);
        $this->assertInstanceOf(OtelSpan::class, $result);
        $span->end();
    }

    /** TRACE-15: credits.quote span is created and chainable in no-op mode. */
    public function test_credits_quote_span_created_in_noop_mode(): void
    {
        $request = Request::create('/v1/credits/quote', 'GET');
        $span = OtelTracer::startSpan($request, 'iicp.directory.credits.quote');
        $this->assertInstanceOf(OtelSpan::class, $span);
        $result = $span->setAttribute('iicp.intent', 'urn:iicp:intent:llm:chat:v1')
            ->setAttribute('iicp.max_tokens', 2000)
            ->setAttribute('iicp.nodes_quoted', 3)
            ->setAttribute('iicp.estimated_credits', 2.0);
        $this->assertInstanceOf(OtelSpan::class, $result);
        $span->end();
    }

    /** TRACE-16: register span is created and chainable in no-op mode. */
    public function test_register_span_created_in_noop_mode(): void
    {
        $request = Request::create('/v1/register', 'POST');
        $span = OtelTracer::startSpan($request, 'iicp.directory.register');
        $this->assertInstanceOf(OtelSpan::class, $span);
        $result = $span->setAttribute('iicp.node_id', 'test-node-uuid-001')
            ->setAttribute('iicp.region', 'eu-central')
            ->setAttribute('iicp.capabilities_count', 2);
        $this->assertInstanceOf(OtelSpan::class, $result);
        $span->end();
    }
}
