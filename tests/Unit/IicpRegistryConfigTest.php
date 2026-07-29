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
        $registrySource = file_get_contents(__DIR__.'/../../app/Services/NodeRegistry.php');
        $verifierSource = file_get_contents(__DIR__.'/../../app/Services/NodeEndpointVerifier.php');
        $persistenceSource = file_get_contents(__DIR__.'/../../app/Services/NodeRegistrationPersistence.php');

        $this->assertIsString($registrySource);
        $this->assertIsString($verifierSource);
        $this->assertIsString($persistenceSource);
        $this->assertStringNotContainsString('env(', $registrySource.$verifierSource.$persistenceSource);
        $this->assertSame(2, substr_count($verifierSource, "config('iicp.registry.skip_liveness_check'"));
        $this->assertStringContainsString(
            "'iicp.registry.max_active_nodes_per_source_ip'",
            $persistenceSource,
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
                'IICP_DEV_ALLOW_INSECURE_TLS' => 'true',
                'IICP_REGISTER_MAX_ACTIVE_NODES_PER_IP' => '7',
            ],
        );

        $this->assertSame([
            'skip_liveness_check' => true,
            'dev_allow_insecure_tls' => true,
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
            'IICP_DEV_ALLOW_INSECURE_TLS' => 'true',
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
                'dev_allow_insecure_tls' => true,
                'max_active_nodes_per_source_ip' => 7,
            ], json_decode($result, true, flags: JSON_THROW_ON_ERROR));
        } finally {
            @unlink($cache);
        }
    }

    public function test_uncached_config_maps_non_secret_replica_environment_values(): void
    {
        $result = $this->runPhp(
            <<<'PHP'
            require 'vendor/autoload.php';
            $config = require 'config/iicp.php';
            echo json_encode($config['replica']);
            PHP,
            $this->replicaEnvironment(),
        );

        $this->assertSame($this->expectedReplicaConfig(), json_decode($result, true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_cached_config_preserves_non_secret_replica_values(): void
    {
        $cache = sys_get_temp_dir().'/iicp-replica-config-'.bin2hex(random_bytes(8)).'.php';
        $buildEnv = [
            'APP_ENV' => 'testing',
            'APP_CONFIG_CACHE' => $cache,
            ...$this->replicaEnvironment(),
        ];

        try {
            $cacheProcess = new Process([PHP_BINARY, 'artisan', 'config:cache'], $this->root(), $buildEnv);
            $cacheProcess->mustRun();
            $result = $this->runPhp(
                <<<'PHP'
                require 'vendor/autoload.php';
                $app = require 'bootstrap/app.php';
                $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
                echo json_encode(config('iicp.replica'));
                PHP,
                [
                    'APP_ENV' => 'testing',
                    'APP_CONFIG_CACHE' => $cache,
                    'IICP_REPLICA_MODE' => 'false',
                    'IICP_SEED_URL' => 'https://changed.invalid',
                    'IICP_DEV_ALLOW_HTTP_DID' => 'false',
                    'IICP_REDIRECT_ENABLED' => 'false',
                    'IICP_REPLICA_URLS' => '',
                    'IICP_REDIRECT_TRUST_TIER' => 'low',
                    'IICP_REDIRECT_RETRY_AFTER' => '99',
                ],
            );

            $this->assertSame($this->expectedReplicaConfig(), json_decode($result, true, flags: JSON_THROW_ON_ERROR));
        } finally {
            @unlink($cache);
        }
    }

    public function test_replica_runtime_consumers_do_not_read_environment_directly(): void
    {
        foreach ([
            'app/Http/Middleware/ReplicaModeRedirect.php',
            'app/Http/Middleware/LoadRedirect.php',
            'app/Http/Controllers/ReplicasController.php',
            'app/Console/Commands/ReplicaPreflightCommand.php',
            'app/Console/Commands/ReplicaStartCommand.php',
        ] as $relative) {
            $source = file_get_contents($this->root().'/'.$relative);
            $this->assertIsString($source);
            foreach ([
                'IICP_REPLICA_MODE',
                'IICP_SEED_URL',
                'IICP_DEV_ALLOW_HTTP_DID',
                'IICP_REDIRECT_ENABLED',
                'IICP_REPLICA_URLS',
                'IICP_REDIRECT_TRUST_TIER',
                'IICP_REDIRECT_RETRY_AFTER',
            ] as $environmentName) {
                $this->assertStringNotContainsString("env('{$environmentName}'", $source, $relative);
            }
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

    private function replicaEnvironment(): array
    {
        return [
            'IICP_REPLICA_MODE' => 'true',
            'IICP_SEED_URL' => 'https://seed.example',
            'IICP_DEV_ALLOW_HTTP_DID' => 'true',
            'IICP_DEV_ALLOW_UNSIGNED_EVENTS' => 'true',
            'IICP_REDIRECT_ENABLED' => 'true',
            'IICP_REPLICA_URLS' => 'https://one.example,https://two.example',
            'IICP_REDIRECT_TRUST_TIER' => 'medium',
            'IICP_REDIRECT_RETRY_AFTER' => '7',
        ];
    }

    private function expectedReplicaConfig(): array
    {
        return [
            'enabled' => true,
            'seed_url' => 'https://seed.example',
            'dev_allow_http_did' => true,
            'dev_allow_unsigned_events' => true,
            'redirect' => [
                'enabled' => true,
                'retry_after' => 7,
                'urls' => 'https://one.example,https://two.example',
                'trust_tier' => 'medium',
            ],
        ];
    }
}
