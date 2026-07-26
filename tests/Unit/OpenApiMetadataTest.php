<?php

namespace Tests\Unit;

use Tests\TestCase;

class OpenApiMetadataTest extends TestCase
{
    public function test_public_license_and_version_namespaces_are_explicit(): void
    {
        $openApi = file_get_contents(base_path('openapi.yaml'));
        $readme = file_get_contents(base_path('README.md'));

        $this->assertIsString($openApi);
        $this->assertIsString($readme);
        $this->assertMatchesRegularExpression(
            '/license:\s+name: Apache 2\.0\s+identifier: Apache-2\.0/',
            $openApi,
        );
        $this->assertStringNotContainsString('name: MIT', $openApi);
        $this->assertMatchesRegularExpression(
            '/The\s+info\.version value versions this\s+documented OpenAPI contract/',
            $openApi,
        );

        foreach (['openapi: 3.1.0', 'info.version: 1.6.0', 'v1.10.76', 'v1.10.76.2'] as $version) {
            $this->assertStringContainsString($version, $readme);
        }
    }
}
