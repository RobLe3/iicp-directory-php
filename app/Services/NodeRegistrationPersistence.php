<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Services;

use App\Models\Node;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Persists fresh and recovered node registrations inside the caller's transaction.
 */
final class NodeRegistrationPersistence
{
    private const DEFAULT_MAX_ACTIVE_NODES_PER_SOURCE_IP = 20;

    public function __construct(
        private NodeEndpointVerifier $endpointVerifier,
    ) {}

    /**
     * @param  array<string,mixed>  $data
     * @param  array<string,mixed>  $pricingAttrs
     * @return array{0:Node,1:bool}
     */
    public function persist(
        array $data,
        string $observedIp,
        string $hashedToken,
        string $hashedProxyToken,
        string $hmacKey,
        array $pricingAttrs,
        bool $publicReachable,
    ): array {
        $existingNodeId = $data['node_id'] ?? null;
        $recovered = false;
        $node = null;

        if ($existingNodeId) {
            $node = Node::where('id', $existingNodeId)->lockForUpdate()->first();
            if ($node) {
                $this->applyReRegistrationUpdate(
                    $node,
                    $data,
                    $observedIp,
                    $hashedToken,
                    $hashedProxyToken,
                    $hmacKey,
                    $publicReachable,
                );
                $recovered = true;
            }
        }

        $nodeId = $existingNodeId ?? (string) Str::uuid();

        if (! $node) {
            $this->assertSourceIpCapacity($observedIp);
            try {
                $node = $this->createFreshNode(
                    $nodeId,
                    $data,
                    $observedIp,
                    $hashedToken,
                    $hashedProxyToken,
                    $hmacKey,
                    $pricingAttrs,
                    $publicReachable,
                );
            } catch (UniqueConstraintViolationException) {
                $node = Node::where('id', $nodeId)->lockForUpdate()->firstOrFail();
                $node->update([
                    'node_token_hash' => $hashedToken,
                    'proxy_token_hash' => $hashedProxyToken,
                    'node_hmac_key' => $hmacKey,
                    'available' => true,
                    'status' => 'active',
                    'last_seen' => now(),
                    'observed_source_ip' => $observedIp,
                    'public_reachable' => $publicReachable,
                ]);
                $recovered = true;
            }
        }

        return [$node, $recovered];
    }

    /** @param array<string,mixed> $data */
    private function applyReRegistrationUpdate(
        Node $node,
        array $data,
        string $observedIp,
        string $hashedToken,
        string $hashedProxyToken,
        string $hmacKey,
        bool $publicReachable,
    ): void {
        $incomingCxKey = $data['cx_public_key'] ?? null;
        $storedCxKey = $node->cx_public_key;
        if ($incomingCxKey !== null && $incomingCxKey !== $storedCxKey) {
            $currentToken = $data['current_node_token'] ?? null;
            if (! $currentToken || ! password_verify($currentToken, $node->node_token_hash)) {
                throw new \InvalidArgumentException('IICP-E049: cx_public_key update requires valid current_node_token');
            }
        }

        $endpointChanged = ($data['endpoint'] ?? null) !== $node->endpoint;
        $transportEndpointChanged = ($data['transport_endpoint'] ?? null) !== $node->transport_endpoint;
        $storedRelayEndpoint = is_array($node->transport_metadata)
            ? ($node->transport_metadata['relay_endpoint'] ?? null)
            : null;
        $incomingRelayEndpoint = array_key_exists('transport_metadata', $data) && is_array($data['transport_metadata'])
            ? ($data['transport_metadata']['relay_endpoint'] ?? null)
            : $storedRelayEndpoint;
        $relayEndpointChanged = $incomingRelayEndpoint !== $storedRelayEndpoint;
        $currentToken = $data['current_node_token'] ?? null;
        $hasOwnership = $currentToken && password_verify($currentToken, $node->node_token_hash);
        $strictSecured = (bool) config('app.iicp_e050_strict_secured', false);
        $secured = filled($node->operator_pubkey) || filled($node->cx_public_key);
        $oldEndpointAlive = false;
        if ($endpointChanged && ! $hasOwnership && ! ($strictSecured && $secured) && $node->endpoint) {
            $oldEndpointAlive = $this->endpointVerifier->isAlive($node->endpoint);
        }
        if (! E050OwnershipPolicy::allows(
            $strictSecured,
            $secured,
            $endpointChanged,
            $transportEndpointChanged,
            $relayEndpointChanged,
            (bool) $hasOwnership,
            $oldEndpointAlive,
        )) {
            throw new \InvalidArgumentException(
                $strictSecured && $secured
                    ? 'IICP-E050: secured-node re-registration requires valid current_node_token'
                    : 'IICP-E050: endpoint change requires current_node_token or the previous endpoint to be unreachable'
            );
        }

        $node->update([
            'endpoint' => $data['endpoint'],
            'transport_endpoint' => $data['transport_endpoint'] ?? null,
            'region' => $data['region'],
            'node_token_hash' => $hashedToken,
            'proxy_token_hash' => $hashedProxyToken,
            'node_hmac_key' => $hmacKey,
            'max_concurrent' => $data['limits']['max_concurrent'],
            'tokens_per_min' => $data['limits']['tokens_per_min'],
            'available' => true,
            'status' => 'active',
            'dormant_since' => null,
            'public_listing' => (bool) ($data['listing']['public_listing'] ?? false),
            'operator_url' => $data['listing']['operator_url'] ?? null,
            'operator_contact' => $data['listing']['operator_contact'] ?? null,
            'last_seen' => now(),
            'observed_source_ip' => $observedIp,
            'transport_method' => $data['transport_method'] ?? $node->transport_method,
            'nat_type' => $data['nat_type'] ?? $node->nat_type,
            'transport_metadata' => $data['transport_metadata'] ?? $node->transport_metadata,
            'public_reachable' => $publicReachable,
            'sdk_language' => $data['sdk_language'] ?? $node->sdk_language,
            'sdk_version' => $data['sdk_version'] ?? $node->sdk_version,
            'supported_receipt_profiles' => $data['supported_receipt_profiles'] ?? null,
            'auto_update_enabled' => $data['auto_update_enabled'] ?? $node->auto_update_enabled,
            'auto_update_interval_s' => $data['auto_update_interval_s'] ?? $node->auto_update_interval_s,
            'sdk_latest_seen' => $data['sdk_latest_seen'] ?? $node->sdk_latest_seen,
            'sdk_update_last_checked_at' => $data['sdk_update_last_checked_at'] ?? $node->sdk_update_last_checked_at,
            'sdk_update_error_class' => $data['sdk_update_error_class'] ?? $node->sdk_update_error_class,
            'backend' => $data['backend'] ?? $node->backend,
            'policy_manifest' => $data['policy_manifest'] ?? $node->policy_manifest,
            'exposure_mode' => $data['exposure_mode'] ?? $node->exposure_mode,
            'cx_public_key' => $incomingCxKey ?? $storedCxKey,
            'gossip_public_key' => $data['public_key'] ?? $node->gossip_public_key,
        ]);
    }

    /**
     * @param  array<string,mixed>  $data
     * @param  array<string,mixed>  $pricingAttrs
     */
    private function createFreshNode(
        string $nodeId,
        array $data,
        string $observedIp,
        string $hashedToken,
        string $hashedProxyToken,
        string $hmacKey,
        array $pricingAttrs,
        bool $publicReachable,
    ): Node {
        return Node::create([
            'id' => $nodeId,
            'endpoint' => $data['endpoint'],
            'transport_endpoint' => $data['transport_endpoint'] ?? null,
            'region' => $data['region'],
            'node_token_hash' => $hashedToken,
            'proxy_token_hash' => $hashedProxyToken,
            'node_hmac_key' => $hmacKey,
            'max_concurrent' => $data['limits']['max_concurrent'],
            'tokens_per_min' => $data['limits']['tokens_per_min'],
            'available' => true,
            'relay_capable' => (bool) ($data['relay_capable'] ?? false),
            'transport_method' => $data['transport_method'] ?? null,
            'nat_type' => $data['nat_type'] ?? null,
            'transport_metadata' => $data['transport_metadata'] ?? null,
            'public_reachable' => $publicReachable,
            'sdk_language' => $data['sdk_language'] ?? null,
            'sdk_version' => $data['sdk_version'] ?? null,
            'supported_receipt_profiles' => $data['supported_receipt_profiles'] ?? null,
            'auto_update_enabled' => $data['auto_update_enabled'] ?? null,
            'auto_update_interval_s' => $data['auto_update_interval_s'] ?? null,
            'sdk_latest_seen' => $data['sdk_latest_seen'] ?? null,
            'sdk_update_last_checked_at' => $data['sdk_update_last_checked_at'] ?? null,
            'sdk_update_error_class' => $data['sdk_update_error_class'] ?? null,
            'backend' => $data['backend'] ?? null,
            'policy_manifest' => $data['policy_manifest'] ?? null,
            'exposure_mode' => $data['exposure_mode'] ?? null,
            'cx_public_key' => $data['cx_public_key'] ?? null,
            'gossip_public_key' => $data['public_key'] ?? null,
            'status' => 'active',
            'dormant_since' => null,
            'identity_key' => hash('sha256', $nodeId),
            'lifetime_jobs' => 0,
            'allow_remote_inference' => (bool) ($data['policy']['allow_remote_inference'] ?? false),
            'allow_tool_execution' => (bool) ($data['policy']['allow_tool_execution'] ?? false),
            'allow_file_access' => (bool) ($data['policy']['allow_file_access'] ?? false),
            'pricing_credits_per_1000' => isset($data['policy']['pricing_credits_per_1000'])
                ? (float) $data['policy']['pricing_credits_per_1000']
                : null,
            'credit_cost_multiplier' => $pricingAttrs['credit_cost_multiplier'],
            'pricing_model' => $pricingAttrs['pricing_model'],
            'declaration_signature' => $pricingAttrs['declaration_signature'],
            'attested' => $pricingAttrs['attested'],
            'pricing_effective_from' => $pricingAttrs['effective_from'],
            'pricing_effective_until' => $pricingAttrs['effective_until'],
            'public_listing' => (bool) ($data['listing']['public_listing'] ?? false),
            'operator_url' => $data['listing']['operator_url'] ?? null,
            'operator_contact' => $data['listing']['operator_contact'] ?? null,
            'last_seen' => now(),
            'observed_source_ip' => $observedIp,
        ]);
    }

    private function assertSourceIpCapacity(string $observedIp): void
    {
        $limit = (int) config(
            'iicp.registry.max_active_nodes_per_source_ip',
            self::DEFAULT_MAX_ACTIVE_NODES_PER_SOURCE_IP,
        );
        if ($limit <= 0 || $observedIp === '0.0.0.0') {
            return;
        }

        $activeForIp = Node::query()
            ->where('observed_source_ip', $observedIp)
            ->where('status', 'active')
            ->count();

        if ($activeForIp >= $limit) {
            throw ValidationException::withMessages([
                'registration_ip' => 'Too many active nodes registered from this source IP (IICP-E052)',
            ]);
        }
    }
}
