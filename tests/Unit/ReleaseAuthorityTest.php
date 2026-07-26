<?php

namespace Tests\Unit;

use Tests\TestCase;

class ReleaseAuthorityTest extends TestCase
{
    public function test_version_truth_and_public_source_authority_are_explicit(): void
    {
        $version = trim(file_get_contents(base_path('VERSION')));
        $config = file_get_contents(base_path('config/app.php'));
        $policy = file_get_contents(base_path('RELEASE_POLICY.md'));

        $this->assertSame('1.10.80.1', $version);
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
        $this->assertStringContainsString('actions/attest@59d89421af93a897026c735860bf21b6eb4f7b26', $workflow);
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
