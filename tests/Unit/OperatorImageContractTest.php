<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OperatorImageContractTest extends TestCase
{
    #[Test]
    public function operator_images_are_pinned_and_do_not_use_reference_shortcuts(): void
    {
        $root = dirname(__DIR__, 2);
        $app = file_get_contents($root.'/Dockerfile.operator');
        $nginx = file_get_contents($root.'/Dockerfile.operator-nginx');

        $this->assertSame(2, preg_match_all('/^FROM .+@sha256:[0-9a-f]{64}(?: AS .+)?$/m', $app));
        $this->assertMatchesRegularExpression('/^FROM .+@sha256:[0-9a-f]{64}$/m', $nginx);
        $this->assertStringNotContainsString('--ignore-platform-reqs', $app);
        $this->assertStringNotContainsString('key:generate', $app);
        $this->assertStringContainsString('USER 10001:10001', $app);
        $this->assertStringContainsString('USER 101:101', $nginx);
    }

    #[Test]
    public function operator_entrypoint_has_a_closed_secret_interface(): void
    {
        $entrypoint = file_get_contents(dirname(__DIR__, 2).'/operator/entrypoint.sh');

        foreach (['APP_KEY', 'DB_PASSWORD'] as $required) {
            $this->assertStringContainsString("load_secret {$required} required", $entrypoint);
        }
        $this->assertStringContainsString('APP_ENV must be production', $entrypoint);
        $this->assertStringContainsString('APP_DEBUG must be false', $entrypoint);
        $this->assertStringNotContainsString('DB_PASSWORD:-', $entrypoint);
        $this->assertStringNotContainsString('php artisan migrate', $entrypoint);
    }
}
