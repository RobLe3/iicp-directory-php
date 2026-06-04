<?php

namespace Tests\Feature;

use App\Rules\RoutableEndpoint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Issue #325 Layer 1: RoutableEndpoint validator rule.
 *
 * Ensures the public registry never accepts an endpoint that cannot be reached
 * from outside the operator's host. Dev environments bypass enforcement so
 * docker-compose stacks continue to work against local directories.
 */
class RoutableEndpointRuleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Enforce production behavior by default so the rule actually runs.
        Config::set('app.env', 'production');
    }

    private function validate(string $endpoint): array
    {
        $validator = Validator::make(
            ['endpoint' => $endpoint],
            ['endpoint' => [new RoutableEndpoint]]
        );

        return $validator->errors()->get('endpoint');
    }

    public function test_accepts_public_dns_endpoint(): void
    {
        $this->assertEmpty($this->validate('https://node1.iicp.example.com:8080'));
    }

    public function test_accepts_public_ipv4(): void
    {
        $this->assertEmpty($this->validate('https://203.0.113.42:8080'));
    }

    public function test_rejects_plain_localhost(): void
    {
        $errors = $this->validate('http://localhost:8090');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('localhost', $errors[0]);
    }

    public function test_rejects_127_loopback_range(): void
    {
        $this->assertNotEmpty($this->validate('http://127.0.0.1:8080'));
        $this->assertNotEmpty($this->validate('http://127.5.5.5:8080'));
    }

    public function test_rejects_zero_dot_zero(): void
    {
        $this->assertNotEmpty($this->validate('http://0.0.0.0:8080'));
    }

    public function test_rejects_ipv6_loopback(): void
    {
        $this->assertNotEmpty($this->validate('http://[::1]:8080'));
    }

    public function test_rejects_rfc1918_10(): void
    {
        $this->assertNotEmpty($this->validate('http://10.0.0.5:8080'));
    }

    public function test_rejects_rfc1918_192_168(): void
    {
        $this->assertNotEmpty($this->validate('http://192.168.1.10:8080'));
    }

    public function test_rejects_rfc1918_172_16_through_31(): void
    {
        $this->assertNotEmpty($this->validate('http://172.16.0.1:8080'));
        $this->assertNotEmpty($this->validate('http://172.20.5.5:8080'));
        $this->assertNotEmpty($this->validate('http://172.31.255.255:8080'));
        // 172.15 and 172.32 must NOT be rejected — they are public
        $this->assertEmpty($this->validate('http://172.15.0.1:8080'));
        $this->assertEmpty($this->validate('http://172.32.0.1:8080'));
    }

    public function test_rejects_link_local_169_254(): void
    {
        $this->assertNotEmpty($this->validate('http://169.254.1.1:8080'));
    }

    public function test_rejects_dot_local_suffix(): void
    {
        $errors = $this->validate('http://myadapter.local:8080');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('.local', $errors[0]);
    }

    public function test_rejects_reserved_suffixes(): void
    {
        $this->assertNotEmpty($this->validate('http://node.test:8080'));
        $this->assertNotEmpty($this->validate('http://node.example:8080'));
        $this->assertNotEmpty($this->validate('http://node.invalid:8080'));
        $this->assertNotEmpty($this->validate('http://node.lan:8080'));
        $this->assertNotEmpty($this->validate('http://service.internal:8080'));
    }

    public function test_rejects_docker_compose_service_names(): void
    {
        $errors = $this->validate('http://adapter-llama:8080');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Docker', $errors[0]);
    }

    public function test_rejects_bare_hostname_without_tld(): void
    {
        $this->assertNotEmpty($this->validate('http://adapter:8080'));
        $this->assertNotEmpty($this->validate('http://service-x:8080'));
    }

    public function test_dev_env_bypasses_all_rejections(): void
    {
        Config::set('app.env', 'local');

        // All the strings that fail under production should pass in dev
        $this->assertEmpty($this->validate('http://localhost:8090'));
        $this->assertEmpty($this->validate('http://adapter-llama:8080'));
        $this->assertEmpty($this->validate('http://192.168.1.10:8080'));
        $this->assertEmpty($this->validate('http://[::1]:8080'));
    }

    public function test_testing_env_also_bypasses(): void
    {
        Config::set('app.env', 'testing');
        $this->assertEmpty($this->validate('http://localhost:8090'));
    }

    public function test_staging_env_does_no_t_bypass(): void
    {
        Config::set('app.env', 'staging');
        // Staging is also a public surface — must reject too
        $this->assertNotEmpty($this->validate('http://localhost:8090'));
    }

    public function test_https_endpoints_with_path_still_validated(): void
    {
        $this->assertNotEmpty($this->validate('http://localhost:8080/model/qwen2.5:0.5b'));
        $this->assertNotEmpty($this->validate('http://adapter-llama:8080/v1/task'));
    }

    public function test_rejects_garbage(): void
    {
        // Note: empty-string is handled by the controller's 'required' rule, not by us.
        $this->assertNotEmpty($this->validate('not-a-url'));
    }

    /** spec v0.7.0: default validator rejects iicp:// scheme (reserved for transport_endpoint). */
    public function test_default_validator_rejects_iicp_scheme(): void
    {
        $errors = $this->validate('iicp://203.0.113.5:9484');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('iicp', $errors[0]);
    }

    /** spec v0.7.0: validator instantiated for transport_endpoint accepts iicp:// + iicpsec://. */
    public function test_transport_endpoint_validator_accepts_iicp_schemes(): void
    {
        $validator = Validator::make(
            ['transport_endpoint' => 'iicp://203.0.113.5:9484'],
            ['transport_endpoint' => [new RoutableEndpoint(['iicp', 'iicpsec'])]]
        );
        $this->assertEmpty($validator->errors()->get('transport_endpoint'));

        $validator = Validator::make(
            ['transport_endpoint' => 'iicpsec://node.example.com:9484'],
            ['transport_endpoint' => [new RoutableEndpoint(['iicp', 'iicpsec'])]]
        );
        $this->assertEmpty($validator->errors()->get('transport_endpoint'));
    }

    /** spec v0.7.0: transport_endpoint validator rejects http:// (wrong scheme for native transport). */
    public function test_transport_endpoint_validator_rejects_http(): void
    {
        $validator = Validator::make(
            ['transport_endpoint' => 'http://203.0.113.5:9484'],
            ['transport_endpoint' => [new RoutableEndpoint(['iicp', 'iicpsec'])]]
        );
        $errors = $validator->errors()->get('transport_endpoint');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('http', $errors[0]);
    }

    /** Routability rules still apply to iicp:// endpoints — RFC1918 host is rejected. */
    public function test_iicp_scheme_still_subject_to_routability_check(): void
    {
        $validator = Validator::make(
            ['transport_endpoint' => 'iicp://192.168.1.10:9484'],
            ['transport_endpoint' => [new RoutableEndpoint(['iicp', 'iicpsec'])]]
        );
        $errors = $validator->errors()->get('transport_endpoint');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('RFC1918', $errors[0]);
    }

    /*
     * #334 — binary NATted model. When the request declares both nat_type
     * (non-empty, non-'none') AND transport_method (non-empty, non-'direct'),
     * the operator has owned the traversal claim — Layer 2 dial-back becomes
     * the truth test. Host classification is skipped.
     */
    public function test_natted_node_with_traversal_method_skips_host_check(): void
    {
        $req = request();
        $req->merge([
            'nat_type' => 'full_cone',
            'transport_method' => 'upnp_mapped',
        ]);
        try {
            // RFC1918 host would normally be rejected — for a declared NATted
            // node it should pass (operator is using UPnP to make it reachable).
            $errors = $this->validate('http://192.168.1.42:8080');
            $this->assertEmpty(
                $errors,
                'NATted node with transport_method=upnp_mapped should bypass host classification',
            );
        } finally {
            $req->replace(array_diff_key($req->all(), array_flip(['nat_type', 'transport_method'])));
        }
    }

    public function test_natted_node_still_rejects_garbage_url(): void
    {
        $req = request();
        $req->merge([
            'nat_type' => 'symmetric',
            'transport_method' => 'turn_relay',
        ]);
        try {
            $errors = $this->validate('not-a-url');
            $this->assertNotEmpty(
                $errors,
                'NATted bypass must still reject malformed URLs',
            );
        } finally {
            $req->replace(array_diff_key($req->all(), array_flip(['nat_type', 'transport_method'])));
        }
    }

    public function test_natted_node_still_rejects_disallowed_scheme(): void
    {
        $req = request();
        $req->merge([
            'nat_type' => 'restricted_cone',
            'transport_method' => 'stun_hole_punch',
        ]);
        try {
            $errors = $this->validate('ftp://example.com:21');
            $this->assertNotEmpty(
                $errors,
                'NATted bypass must still reject schemes outside the allowedSchemes list',
            );
            $this->assertStringContainsString('scheme', $errors[0]);
        } finally {
            $req->replace(array_diff_key($req->all(), array_flip(['nat_type', 'transport_method'])));
        }
    }

    public function test_transport_method_direct_does_no_t_trigger_natted_bypass(): void
    {
        // transport_method=direct means "not NATted" — host check still applies
        $req = request();
        $req->merge([
            'nat_type' => 'none',
            'transport_method' => 'direct',
        ]);
        try {
            $errors = $this->validate('http://localhost:8080');
            $this->assertNotEmpty(
                $errors,
                'transport_method=direct should NOT bypass host classification',
            );
            $this->assertStringContainsString('localhost', $errors[0]);
        } finally {
            $req->replace(array_diff_key($req->all(), array_flip(['nat_type', 'transport_method'])));
        }
    }

    /*
     * IPv6 + CGNAT (RFC 6598 100.64.0.0/10) rejection paths.
     * Maintainer directive 2026-05-27: 'We should allow and implement
     * IPv6 and CGNAT support.' IPv6 GUA (2000::/3) is accepted; private
     * / link-local / unique-local / multicast / documentation rejected.
     * 100.64/10 explicitly rejected with a tunnel-recommend message.
     */
    public function test_accepts_public_ipv6_gua(): void
    {
        $this->assertEmpty($this->validate('https://[2606:4700:4700::1111]:8080'));
    }

    public function test_rejects_ipv6_link_local(): void
    {
        $errors = $this->validate('https://[fe80::1]:8080');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('fe80', $errors[0]);
    }

    public function test_rejects_ipv6_unique_local(): void
    {
        $errors = $this->validate('https://[fc00::1]:8080');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('fc00', $errors[0]);
    }

    public function test_rejects_ipv6_documentation(): void
    {
        $errors = $this->validate('https://[2001:db8::1]:8080');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('2001:db8', $errors[0]);
    }

    public function test_rejects_cgnat_100_64_ipv4(): void
    {
        $errors = $this->validate('https://100.64.5.10:8080');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('CGNAT', $errors[0]);
        $this->assertStringContainsString('tunnel', $errors[0]);
    }

    public function test_rejects_cgnat_100_127_ipv4(): void
    {
        $errors = $this->validate('https://100.127.255.254:8080');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('CGNAT', $errors[0]);
    }

    public function test_accepts_100_128_outside_cgnat_block(): void
    {
        // 100.128.x is just outside the 100.64.0.0/10 range — valid public IPv4.
        $this->assertEmpty($this->validate('https://100.128.1.1:8080'));
    }
}
