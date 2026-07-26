<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Unit;

use App\Services\RuntimeSecretProvider;
use InvalidArgumentException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class RuntimeSecretProviderTest extends TestCase
{
    private RuntimeSecretProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new RuntimeSecretProvider;
    }

    protected function tearDown(): void
    {
        foreach ([
            RuntimeSecretProvider::GENESIS_SEED_SECRET_KEY,
            RuntimeSecretProvider::REPLICA_ED25519_SECRET_KEY,
            RuntimeSecretProvider::DEV_DID_DOCUMENT_JSON,
        ] as $name) {
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);
        }

        parent::tearDown();
    }

    public function test_it_rejects_names_outside_the_allowlist(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->provider->get('DB_PASSWORD');
    }

    public function test_it_reads_environment_sources_without_normalizing_secret_content(): void
    {
        $_ENV[RuntimeSecretProvider::GENESIS_SEED_SECRET_KEY] = 'env-value';
        $_SERVER[RuntimeSecretProvider::REPLICA_ED25519_SECRET_KEY] = 'server-value';
        putenv(RuntimeSecretProvider::DEV_DID_DOCUMENT_JSON.'={"opaque":true}');

        $this->assertSame(
            'env-value',
            $this->provider->get(RuntimeSecretProvider::GENESIS_SEED_SECRET_KEY)
        );
        $this->assertSame(
            'server-value',
            $this->provider->get(RuntimeSecretProvider::REPLICA_ED25519_SECRET_KEY)
        );
        $this->assertSame(
            '{"opaque":true}',
            $this->provider->get(RuntimeSecretProvider::DEV_DID_DOCUMENT_JSON)
        );
    }

    public function test_it_treats_missing_and_empty_values_as_absent(): void
    {
        putenv(RuntimeSecretProvider::GENESIS_SEED_SECRET_KEY.'=');

        $this->assertNull($this->provider->get(RuntimeSecretProvider::GENESIS_SEED_SECRET_KEY));
        $this->assertNull($this->provider->get(RuntimeSecretProvider::REPLICA_ED25519_SECRET_KEY));
    }

    public function test_runtime_secrets_are_not_serialized_into_the_config_cache(): void
    {
        $cache = sys_get_temp_dir().'/iicp-secret-config-'.bin2hex(random_bytes(8)).'.php';
        $genesisSecret = 'genesis-'.bin2hex(random_bytes(16));
        $replicaSecret = 'replica-'.bin2hex(random_bytes(16));

        try {
            $process = new Process(
                [PHP_BINARY, 'artisan', 'config:cache'],
                dirname(__DIR__, 2),
                [
                    'APP_ENV' => 'testing',
                    'APP_CONFIG_CACHE' => $cache,
                    RuntimeSecretProvider::GENESIS_SEED_SECRET_KEY => $genesisSecret,
                    RuntimeSecretProvider::REPLICA_ED25519_SECRET_KEY => $replicaSecret,
                ],
            );
            $process->mustRun();
            $contents = file_get_contents($cache);

            $this->assertIsString($contents);
            $this->assertStringNotContainsString($genesisSecret, $contents);
            $this->assertStringNotContainsString($replicaSecret, $contents);
        } finally {
            @unlink($cache);
        }
    }
}
