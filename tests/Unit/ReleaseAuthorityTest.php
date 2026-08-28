<?php

namespace Tests\Unit;

use Tests\TestCase;

class ReleaseAuthorityTest extends TestCase
{
    public function test_minimum_php_runtime_is_declared_and_candidate_remains_pre1(): void
    {
        $composer = json_decode(
            file_get_contents(base_path('composer.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $version = trim(file_get_contents(base_path('VERSION')));

        $this->assertSame('^8.3', $composer['require']['php']);
        $this->assertSame('8.3.30', $composer['config']['platform']['php']);
        $this->assertTrue(version_compare(PHP_VERSION, '8.3.0', '>='));
        $this->assertTrue(version_compare($version, '2.0.0', '<'));
    }

    public function test_preview_contract_never_authorizes_dual_genesis(): void
    {
        $readme = file_get_contents(base_path('README.md'));
        $policy = file_get_contents(base_path('RELEASE_POLICY.md'));

        $this->assertStringContainsString('PHP is the supported implementation behind the current Genesis Seed', $readme);
        $this->assertMatchesRegularExpression('/Rust directory.*pre-1\.0\s+operator preview/s', $readme);
        $this->assertStringContainsString('does not authorize deployment to the Genesis Seed', $policy);
    }

    public function test_version_truth_and_public_source_authority_are_explicit(): void
    {
        $version = trim(file_get_contents(base_path('VERSION')));
        $config = file_get_contents(base_path('config/app.php'));
        $policy = file_get_contents(base_path('RELEASE_POLICY.md'));

        $this->assertSame('1.10.93', $version);
        $this->assertStringContainsString("'iicp_version' => 'v{$version}'", $config);
        $this->assertStringContainsString('authoritative PHP directory source', $policy);
        $this->assertMatchesRegularExpression('/do not define\\s+current\\s+source/', $policy);
        $this->assertStringContainsString('does not authorize deployment', $policy);
    }

    public function test_release_workflow_is_tag_only_attested_and_refuses_asset_replacement(): void
    {
        $workflow = file_get_contents(base_path('.github/workflows/release.yml'));

        $this->assertStringContainsString("tags:\n", $workflow);
        $this->assertStringNotContainsString('workflow_dispatch', $workflow);
        $this->assertStringContainsString('actions/attest@1e69f48acb82d1966a394da916b4c1698aa569d6', $workflow);
        $this->assertStringContainsString('release already exists; assets are immutable', $workflow);
        $this->assertStringContainsString('--verify-tag', $workflow);
    }

    public function test_release_archive_builder_is_content_free_and_deterministic(): void
    {
        $builder = file_get_contents(base_path('scripts/build_release_artifacts.sh'));

        $this->assertStringContainsString('git -C "$ROOT" archive', $builder);
        $this->assertStringContainsString('gzip -n -9', $builder);
        $this->assertStringContainsString('sha256sum', $builder);
        $this->assertStringNotContainsString('.env.example .env', $builder);
    }
}
