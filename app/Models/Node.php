<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Node extends Model
{
    use HasUuids;

    protected $fillable = [
        'id',
        'endpoint',
        'region',
        'load',
        'active_jobs',
        'available',
        'relay_capable',         // ADR-022: node can forward tasks to peers on behalf of consumers
        'backend',               // detected backend server flavor: ollama/lmstudio/vllm/llamacpp/anthropic/custom
        'health_models',         // #494: runtime model list from last heartbeat; null=not reported yet
        'backend_stability',      // #561: provider-local backend/model readiness; separate from network reachability
        'last_seen',
        'node_token_hash',
        'proxy_token_hash',      // #114: separate token for proxy telemetry auth
        'node_hmac_key',         // IICP-E027: plaintext HMAC key for CIPWorkerReceipt verification
        'max_concurrent',
        'tokens_per_min',
        'did_public_key',        // Phase 3 DID identity — nullable, not used in Phase 1 REGISTER
        'observed_source_ip',    // DIR-ADDR-01: updated on every register/heartbeat
        // CIP-D1: Provider opt-in policy block (spec S.12 §2.1)
        'allow_remote_inference',
        'allow_tool_execution',
        'allow_file_access',
        'pricing_credits_per_1000',
        // ADR-019 declarative pricing
        'credit_cost_multiplier',
        'pricing_model',
        'declaration_signature',
        'attested',
        'pricing_effective_from',
        'pricing_effective_until',
        // Reputation persistence (v0.5.0 patch)
        'status',
        'dormant_since',
        'identity_key',
        'lifetime_jobs',
        // ADR-017: Operator opt-in public listing fields
        'public_listing',
        'operator_url',
        'operator_contact',
        // ADR-045 Phase A — verifiable operator→node binding (ed25519 delegation)
        'operator_pubkey',
        'operator_verified',
        'operator_trust_tier',
        // ADR-047 Part A — heartbeat challenge-response cryptographic liveness
        'liveness_challenge',
        'liveness_verified_at',
        // W-042 / db-D1prime — canonical denormalized fields
        'reputation_score',
        'credit_balance',
        // D2prime-followup — remaining canonical fields denormalized for Phase 2 drop safety
        'tasks_total',
        'tasks_failed',
        'avg_latency_ms',
        'free_credit_last_allocation_at',
        // Phase A.2 / ADR-036 — rolling reputation window (aligned to credit TTL)
        'tasks_total_recent',
        'tasks_failed_recent',
        'avg_latency_ms_recent',
        'recent_window_start',
        // #331 Phase A.1 / ADR-041 — NAT-traversal observability
        'transport_method',
        'nat_type',
        'transport_metadata',
        // spec v0.7.0 — native IICP binary endpoint (separate from HTTP control plane)
        'transport_endpoint',
        // #326 — separates externally-reachable from internal-only
        'public_reachable',
        // #536 — confirmed-dead listed endpoint; default discover excludes until a probe clears it
        'endpoint_verified_dead_at',
        // SDK identification — free-form so future languages can self-tag
        'sdk_language',
        'sdk_version',
        // #521 follow-up — optional updater evidence from provider heartbeats
        'auto_update_enabled',
        'auto_update_interval_s',
        'sdk_latest_seen',
        'sdk_update_last_checked_at',
        'sdk_update_error_class',
        // ADR-043 §9 — 8-category network exposure classification
        'exposure_mode',
        // IICP-CX S.16 §3.1 — X25519 public key for E2E payload confidentiality (#360)
        'cx_public_key',
        // #495 iicp-dir.md §3.6 — Ed25519 gossip signing key for PEER_EXCHANGE auth
        'gossip_public_key',
        // RT-01b (#381) — per-node hourly reputation velocity ceiling
        'rep_hourly_gain',
        'rep_hourly_window_start',
    ];

    protected $casts = [
        'available' => 'boolean',
        'public_reachable' => 'boolean',
        'endpoint_verified_dead_at' => 'datetime',
        'relay_capable' => 'boolean',
        'allow_remote_inference' => 'boolean',
        'allow_tool_execution' => 'boolean',
        'allow_file_access' => 'boolean',
        'pricing_credits_per_1000' => 'float',
        'credit_cost_multiplier' => 'float',
        'attested' => 'boolean',
        'public_listing' => 'boolean',
        'pricing_effective_from' => 'datetime',
        'pricing_effective_until' => 'datetime',
        'load' => 'float',
        'last_seen' => 'datetime',
        'dormant_since' => 'datetime',
        'liveness_verified_at' => 'datetime',  // ADR-047 Part A
        'lifetime_jobs' => 'integer',
        // W-042 / db-D1prime — canonical denormalized fields
        'reputation_score' => 'float',
        'credit_balance' => 'decimal:4',
        // D2prime-followup
        'tasks_total' => 'integer',
        'tasks_failed' => 'integer',
        'avg_latency_ms' => 'float',
        'free_credit_last_allocation_at' => 'datetime',
        // Phase A.2 / ADR-036
        'tasks_total_recent' => 'integer',
        'tasks_failed_recent' => 'integer',
        'avg_latency_ms_recent' => 'float',
        'recent_window_start' => 'datetime',
        // #494 — runtime health model list (null = not reported; [] = no models live)
        'health_models' => 'array',
        // #561 — redacted backend stability report from SDK observers
        'backend_stability' => 'array',
        // #331 Phase A.1 / ADR-041 — NAT-traversal observability
        'transport_metadata' => 'array',
        // IICP-CX S.16 §3.1 — X25519 public key advertisement (#360)
        'cx_public_key' => 'array',
        'auto_update_enabled' => 'boolean',
        'auto_update_interval_s' => 'integer',
        'sdk_update_last_checked_at' => 'datetime',
        // RT-01b (#381) — per-node hourly reputation velocity ceiling
        'rep_hourly_gain' => 'float',
        'rep_hourly_window_start' => 'datetime',
    ];

    public function scopeRoutable($query)
    {
        return $query->where('status', 'active')->where('available', true);
    }

    protected $hidden = ['node_token_hash', 'proxy_token_hash', 'node_hmac_key'];

    public function capabilities(): HasMany
    {
        return $this->hasMany(Capability::class);
    }

    public function availabilityWindows(): HasMany
    {
        return $this->hasMany(AvailabilityWindow::class);
    }

    public function reputation(): HasOne
    {
        return $this->hasOne(Reputation::class);
    }

    public function credits(): HasOne
    {
        return $this->hasOne(Credit::class);
    }
}
