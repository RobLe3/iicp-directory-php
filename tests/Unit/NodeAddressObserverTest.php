<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Unit;

use App\Services\NodeAddressObserver;
use Tests\TestCase;

class NodeAddressObserverTest extends TestCase
{
    private NodeAddressObserver $obs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->obs = new NodeAddressObserver;
    }

    // --- isPrivateIp ---

    public function test_rfc1918_addresses_are_private(): void
    {
        $this->assertTrue($this->obs->isPrivateIp('10.0.0.1'));
        $this->assertTrue($this->obs->isPrivateIp('172.16.0.1'));
        $this->assertTrue($this->obs->isPrivateIp('192.168.1.100'));
    }

    public function test_loopback_is_private(): void
    {
        $this->assertTrue($this->obs->isPrivateIp('127.0.0.1'));
    }

    public function test_public_addresses_are_not_private(): void
    {
        $this->assertFalse($this->obs->isPrivateIp('198.51.100.42'));
        $this->assertFalse($this->obs->isPrivateIp('203.0.113.1'));
        $this->assertFalse($this->obs->isPrivateIp('8.8.8.8'));
    }

    public function test_invalid_ip_returns_false(): void
    {
        $this->assertFalse($this->obs->isPrivateIp('not-an-ip'));
        $this->assertFalse($this->obs->isPrivateIp(''));
    }

    // --- isLocalRegion ---

    public function test_local_keywords_match(): void
    {
        $this->assertTrue($this->obs->isLocalRegion('local'));
        $this->assertTrue($this->obs->isLocalRegion('dev'));
        $this->assertTrue($this->obs->isLocalRegion('test'));
        $this->assertTrue($this->obs->isLocalRegion('localhost'));
        $this->assertTrue($this->obs->isLocalRegion('loopback'));
        $this->assertTrue($this->obs->isLocalRegion('lan'));
        $this->assertTrue($this->obs->isLocalRegion('private'));
    }

    public function test_keyword_matching_is_case_insensitive(): void
    {
        $this->assertTrue($this->obs->isLocalRegion('Local'));
        $this->assertTrue($this->obs->isLocalRegion('DEV'));
        $this->assertTrue($this->obs->isLocalRegion('TEST-REGION'));
    }

    public function test_keyword_matches_substring(): void
    {
        $this->assertTrue($this->obs->isLocalRegion('my-dev-cluster'));
        $this->assertTrue($this->obs->isLocalRegion('local-dc-01'));
    }

    public function test_production_regions_are_not_local(): void
    {
        $this->assertFalse($this->obs->isLocalRegion('eu-central'));
        $this->assertFalse($this->obs->isLocalRegion('us-west-2'));
        $this->assertFalse($this->obs->isLocalRegion('ap-southeast-1'));
    }

    public function test_empty_region_is_not_local(): void
    {
        $this->assertFalse($this->obs->isLocalRegion(''));
    }
}
