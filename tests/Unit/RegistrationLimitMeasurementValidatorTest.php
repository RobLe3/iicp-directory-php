<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class RegistrationLimitMeasurementValidatorTest extends TestCase
{
    public function test_blank_measurement_template_is_valid_but_not_evidence(): void
    {
        exec('python3 scripts/check_registration_limit_measurement.py 2>&1', $output, $status);
        $this->assertSame(0, $status, implode("\n", $output));
        $record = json_decode(file_get_contents('evidence/registration-limit-measurement-v1.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertFalse($record['result_present']);
        $this->assertFalse($record['claim_boundary']['authorizes_production_change']);
    }
}
