<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Services;

/**
 * ADR-045 Phase A — verify an operator→node delegation OFFLINE (#407 / #2).
 *
 * A fleet operator signs a compact ed25519 token asserting `node:<id>` is
 * operated by `<operator_pubkey>` until `<not_after>`. Any federated directory
 * verifies it locally — no phone-home — against its set of trusted operator
 * public keys. Proven in research #406 (correct on all 6 attack cases, ~190µs).
 *
 * Token shape (compact, ~205 B):
 *   { "node_id": str, "operator_pub": base64(ed25519 pubkey, 32B),
 *     "not_after": int (unix seconds), "sig": base64(ed25519 sig, 64B) }
 *
 * CANONICAL SIGNING BYTES (must be byte-identical across the PHP verifier and
 * every SDK signer — to be ratified in spec as the ADR-045 canonical form):
 *   json_encode({node_id, not_after, operator_pub}, key-sorted, no spaces,
 *   unescaped slashes/unicode). The `sig` covers exactly these bytes.
 *
 * Phase A trust model (OPEN-1 A / OPEN-2 A): the directory holds a set of
 * trusted operator public keys (did:web-resolved domain operators = higher tier,
 * did:key = lower). Revocation (OPEN-3 C): short-TTL `not_after` is the baseline;
 * an optional revoked-node-id set provides instant kill.
 */
class OperatorDelegationVerifier
{
    /**
     * @param  array<string,mixed>  $token  the delegation token (see class doc)
     * @param  string  $claimedNodeId  the node_id the REGISTER payload claims
     * @param  list<string>  $trustedOperatorPubs  base64 ed25519 pubkeys the directory trusts
     * @param  list<string>  $revokedNodeIds  node_ids with revoked delegations (instant kill)
     * @param  int|null  $now  unix seconds (defaults to time())
     * @return array{0: bool, 1: string} [ok, reason]
     *                                   reason ∈ {ok, untrusted_operator, node_id_mismatch, revoked, expired,
     *                                   malformed, bad_signature}
     */
    public function verify(
        array $token,
        string $claimedNodeId,
        array $trustedOperatorPubs,
        array $revokedNodeIds = [],
        ?int $now = null,
    ): array {
        $now ??= time();

        $opPub = $token['operator_pub'] ?? null;
        $nodeId = $token['node_id'] ?? null;
        $notAfter = $token['not_after'] ?? null;
        $sig = $token['sig'] ?? null;

        if (! is_string($opPub) || ! is_string($nodeId) || ! is_string($sig) || ! is_int($notAfter)) {
            return [false, 'malformed'];
        }
        if (! in_array($opPub, $trustedOperatorPubs, true)) {
            return [false, 'untrusted_operator'];
        }
        if ($nodeId !== $claimedNodeId) {
            return [false, 'node_id_mismatch'];
        }
        if (in_array($nodeId, $revokedNodeIds, true)) {
            return [false, 'revoked'];
        }
        if ($now >= $notAfter) {
            return [false, 'expired'];
        }

        $pubRaw = base64_decode($opPub, true);
        $sigRaw = base64_decode($sig, true);
        // ed25519 sizes are protocol-fixed: pubkey 32 B, signature 64 B. Hardcoded rather than the
        // SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES / SODIUM_CRYPTO_SIGN_BYTES constants — those globals are
        // not reliably defined on the prod PHP (sodium polyfill), and an unqualified reference inside
        // this namespace 500'd EVERY operator-delegation register (so no operator ever bound).
        if ($pubRaw === false || strlen($pubRaw) !== 32
            || $sigRaw === false || strlen($sigRaw) !== 64) {
            return [false, 'malformed'];
        }

        $message = self::canonicalBytes($nodeId, $opPub, $notAfter);
        try {
            $valid = sodium_crypto_sign_verify_detached($sigRaw, $message, $pubRaw);
        } catch (\SodiumException) {
            return [false, 'bad_signature'];
        }

        return $valid ? [true, 'ok'] : [false, 'bad_signature'];
    }

    /**
     * Canonical bytes the operator signs / the directory verifies. Key-sorted,
     * no whitespace, unescaped slashes + unicode. MUST match every SDK signer.
     */
    public static function canonicalBytes(string $nodeId, string $operatorPub, int $notAfter): string
    {
        return json_encode(
            ['node_id' => $nodeId, 'not_after' => $notAfter, 'operator_pub' => $operatorPub],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }
}
