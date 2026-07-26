<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class IicpRegistryConfigTest extends TestCase
{
    public function test_registry_environment_names_and_defaults_are_preserved_in_config(): void
    {
        $source = file_get_contents(__DIR__.'/../../config/iicp.php');

        $this->assertIsString($source);
        $this->assertStringContainsString("env('IICP_SKIP_LIVENESS_CHECK', false)", $source);
        $this->assertStringContainsString("env('IICP_REGISTER_MAX_ACTIVE_NODES_PER_IP', 20)", $source);
    }

    public function test_node_registry_reads_config_instead_of_environment(): void
    {
        $source = file_get_contents(__DIR__.'/../../app/Services/NodeRegistry.php');

        $this->assertIsString($source);
        $this->assertStringNotContainsString('env(', $source);
        $this->assertSame(2, substr_count($source, "config('iicp.registry.skip_liveness_check'"));
        $this->assertStringContainsString(
            "'iicp.registry.max_active_nodes_per_source_ip'",
            $source,
        );
    }

    public function test_uncached_config_maps_registry_environment_values(): void
    {
        $result = $this->runPhp(
            <<<'PHP'
            require 'vendor/autoload.php';
            $config = require 'config/iicp.php';
            echo json_encode($config['registry']);
            PHP,
            [
                'IICP_SKIP_LIVENESS_CHECK' => 'true',
                'IICP_REGISTER_MAX_ACTIVE_NODES_PER_IP' => '7',
            ],
        );

        $this->assertSame([
            'skip_liveness_check' => true,
            'max_active_nodes_per_source_ip' => 7,
        ], json_decode($result, true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_cached_config_preserves_registry_values_without_runtime_env_reads(): void
    {
        $cache = sys_get_temp_dir().'/iicp-config-'.bin2hex(random_bytes(8)).'.php';
        $buildEnv = [
            'APP_ENV' => 'testing',
            'APP_CONFIG_CACHE' => $cache,
            'IICP_SKIP_LIVENESS_CHECK' => 'true',
            'IICP_REGISTER_MAX_ACTIVE_NODES_PER_IP' => '7',
        ];

        try {
            $cacheProcess = new Process([PHP_BINARY, 'artisan', 'config:cache'], $this->root(), $buildEnv);
            $cacheProcess->mustRun();
            $result = $this->runPhp(
                <<<'PHP'
                require 'vendor/autoload.php';
                $app = require 'bootstrap/app.php';
                $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
                echo json_encode(config('iicp.registry'));
                PHP,
                [
                    'APP_ENV' => 'testing',
                    'APP_CONFIG_CACHE' => $cache,
                    'IICP_SKIP_LIVENESS_CHECK' => 'false',
                    'IICP_REGISTER_MAX_ACTIVE_NODES_PER_IP' => '99',
                ],
            );

            $this->assertSame([
                'skip_liveness_check' => true,
                'max_active_nodes_per_source_ip' => 7,
            ], json_decode($result, true, flags: JSON_THROW_ON_ERROR));
        } finally {
            @unlink($cache);
        }
    }

    private function runPhp(string $code, array $environment): string
    {
        $process = new Process([PHP_BINARY, '-r', $code], $this->root(), $environment);
        $process->mustRun();

        return $process->getOutput();
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
