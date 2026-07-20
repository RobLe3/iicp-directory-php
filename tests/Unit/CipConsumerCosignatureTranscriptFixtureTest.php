<?php

namespace Tests\Unit;

use Tests\TestCase;

class CipConsumerCosignatureTranscriptFixtureTest extends TestCase
{
    public function test_transcript_is_content_free_consistent_and_fail_closed(): void
    {
        $path = dirname(__DIR__, 2).'/parity/cip-consumer-cosignature-transcript-v1.json';
        $data = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $messages = array_column($data['transcript'], 'message');

        $this->assertSame(['receipt_offer', 'receipt_acceptance', 'settlement_request'], array_column($messages, 'type'));
        $this->assertCount(1, array_unique(array_column($messages, 'receipt_digest_hex')));
        $this->assertTrue($data['privacy_contract']['content_free']);
        $rendered = json_encode($data, JSON_THROW_ON_ERROR);
        foreach ($data['privacy_contract']['forbidden_fields'] as $field) {
            $this->assertStringNotContainsString('"'.$field.'":', $rendered);
        }
        foreach ($data['transition_modes'] as $mode) {
            $this->assertFalse($mode['strict_enforcement_authorized']);
        }
        $required = collect($data['transition_modes'])->firstWhere('mode', 'required');
        $this->assertSame('unavailable', $required['runtime_status']);
    }
}
