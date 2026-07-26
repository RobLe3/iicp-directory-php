<?php

namespace Tests\Feature;

use App\Http\Controllers\RegisterController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Http;
use ReflectionClass;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class OpenApiContractTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function contract(): array
    {
        return Yaml::parseFile(base_path('openapi.yaml'));
    }

    /** @return array<string, mixed> */
    private function routeClassification(): array
    {
        return json_decode(
            file_get_contents(base_path('contracts/route-classification.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    public function test_every_application_route_has_a_reviewed_classification(): void
    {
        $ignoredFrameworkRoutes = [
            'GET sanctum/csrf-cookie',
            'GET storage/{path}',
            'PUT storage/{path}',
            'GET up',
        ];
        $actual = [];

        /** @var Route $route */
        foreach (app('router')->getRoutes() as $route) {
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $key = $method.' '.$route->uri();
                if (in_array($key, $ignoredFrameworkRoutes, true)) {
                    continue;
                }
                $actual[$key] = array_map(
                    static fn (string $middleware): string => str_starts_with($middleware, 'throttle:')
                        ? 'Illuminate\\Routing\\Middleware\\ThrottleRequests:'.substr($middleware, 9)
                        : $middleware,
                    array_values($route->gatherMiddleware()),
                );
            }
        }
        ksort($actual);

        $classified = [];
        foreach ($this->routeClassification()['routes'] as $route) {
            $key = $route['method'].' '.$route['uri'];
            $this->assertNotSame('', trim($route['classification']));
            $this->assertNotSame('', trim($route['rationale']));
            $classified[$key] = $route['middleware'];
        }
        ksort($classified);

        $this->assertSame($actual, $classified);
    }

    public function test_openapi_operations_map_to_real_classified_routes(): void
    {
        $contract = $this->contract();
        $documented = [];
        foreach ($this->routeClassification()['routes'] as $route) {
            if ($route['openapi_path'] !== null) {
                $documented[strtolower($route['method']).' '.$route['openapi_path']] = true;
            }
        }

        $operations = [];
        foreach ($contract['paths'] as $path => $pathItem) {
            foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
                if (isset($pathItem[$method])) {
                    $operations[$method.' '.$path] = true;
                }
            }
        }

        ksort($documented);
        ksort($operations);
        $this->assertSame($documented, $operations);
    }

    public function test_registration_contract_matches_runtime_status_limit_and_intent_grammar(): void
    {
        $contract = $this->contract();
        $registration = $contract['paths']['/register']['post'];

        $this->assertArrayHasKey('201', $registration['responses']);
        $this->assertArrayNotHasKey('200', $registration['responses']);
        $this->assertStringContainsString('60 requests per minute', $registration['description']);
        $this->assertSame(
            'https://iicp.network/api/v1',
            $contract['servers'][0]['url'],
        );

        $reflection = new ReflectionClass(RegisterController::class);
        $runtimePattern = $reflection->getReflectionConstant('INTENT_PATTERN')->getValue();
        $runtimeLimit = $reflection->getReflectionConstant('REGISTER_RATE_LIMIT')->getValue();
        $documentedPattern = $contract['components']['schemas']['Capability']['properties']['intent']['pattern'];

        $this->assertSame(60, $runtimeLimit);
        $this->assertSame(trim($runtimePattern, '/'), $documentedPattern);
        $this->assertMatchesRegularExpression($runtimePattern, 'urn:iicp:intent:x.acme:chat:v1');
        $this->assertDoesNotMatchRegularExpression($runtimePattern, 'urn:iicp:intent:x.acme:chat:v0');
    }

    public function test_registration_accepts_documented_custom_intent_and_rejects_invalid_version(): void
    {
        Http::fake(['https://node.example.com/iicp/health' => Http::response('ok', 200)]);
        $payload = [
            'endpoint' => 'https://node.example.com',
            'region' => 'eu-central',
            'capabilities' => [[
                'intent' => 'urn:iicp:intent:x.acme:chat:v1',
                'models' => ['reference-model'],
                'max_tokens' => 1024,
            ]],
            'limits' => ['max_concurrent' => 1, 'tokens_per_min' => 1000],
        ];

        $this->postJson('/api/v1/register', $payload)->assertCreated();

        $payload['endpoint'] = 'https://invalid.example.com';
        $payload['capabilities'][0]['intent'] = 'urn:iicp:intent:x.acme:chat:v0';
        $this->postJson('/api/v1/register', $payload)
            ->assertUnprocessable();
    }

    public function test_identifier_and_security_metadata_match_supported_behavior(): void
    {
        $contract = $this->contract();
        $nodePattern = $contract['components']['schemas']['RegistrationResponse']['properties']['node_id']['pattern'];

        $this->assertMatchesRegularExpression('/'.$nodePattern.'/', 'relay-eu-e50fc7f9');
        $this->assertDoesNotMatchRegularExpression('/'.$nodePattern.'/', 'bad node id');
        $this->assertSame(
            [['bearerAuth' => []]],
            $contract['paths']['/heartbeat']['post']['security'],
        );
        $this->assertArrayNotHasKey('security', $contract['paths']['/discover']['get']);
    }

    public function test_all_schema_references_resolve_inside_the_public_contract(): void
    {
        $contract = $this->contract();
        $references = [];
        $visit = function (mixed $value) use (&$visit, &$references): void {
            if (! is_array($value)) {
                return;
            }
            if (isset($value['$ref'])) {
                $references[] = $value['$ref'];
            }
            foreach ($value as $nested) {
                $visit($nested);
            }
        };
        $visit($contract);

        foreach (array_unique($references) as $reference) {
            $this->assertStringStartsWith('#/', $reference, "External or missing contract reference: {$reference}");
            $resolved = $contract;
            foreach (explode('/', substr($reference, 2)) as $segment) {
                $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
                $this->assertIsArray($resolved);
                $this->assertArrayHasKey($segment, $resolved, "Unresolved OpenAPI reference: {$reference}");
                $resolved = $resolved[$segment];
            }
        }
    }
}
