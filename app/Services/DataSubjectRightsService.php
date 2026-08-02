<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Services;

use App\Models\DataSubjectAction;
use App\Models\Node;
use App\Models\Operator;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Phase-1 GDPR data-subject-rights helper for directory/operator records.
 *
 * The service is intentionally admin/internal. It exports only redacted records,
 * hides secrets, and records DSR actions without storing prompts, credentials,
 * full operator keys or identity documents.
 */
class DataSubjectRightsService
{
    private const RETENTION_REASON = 'Minimal ledger/security/accounting records retained; public/operator-identifying fields removed or restricted where possible.';

    /** @param array<string,string|null> $selector */
    public function export(array $selector, ?string $trackingId = null): array
    {
        $subject = $this->resolveSubject($selector);

        return [
            'schema' => 'iicp.dsr.export.v1',
            'generated_at' => now()->toIso8601String(),
            'tracking_id' => $trackingId,
            'selector' => $subject['redacted_selector'],
            'subject_hash' => $subject['subject_hash'],
            'retention_notice' => self::RETENTION_REASON,
            'records' => [
                'operators' => $this->exportOperators($subject['operators']),
                'nodes' => $this->exportNodes($subject['nodes']),
                'capabilities' => $this->tableRows('capabilities', $subject['node_ids'], [
                    'node_id', 'intent', 'models', 'max_tokens', 'quantization', 'inference_engine', 'input_modalities',
                ]),
                'credits' => $this->tableRows('credits', $subject['node_ids'], [
                    'node_id', 'balance', 'free_credit_last_allocation_at', 'created_at', 'updated_at',
                ]),
                'credit_transactions' => $this->tableRows('credit_transactions', $subject['node_ids'], [
                    'id', 'node_id', 'amount', 'type', 'task_id', 'reason', 'expires_at', 'created_at',
                ]),
                'reputations' => $this->tableRows('reputations', $subject['node_ids'], [
                    'node_id', 'score', 'tasks_total', 'tasks_failed', 'completed_tasks_count', 'avg_latency_ms', 'observed_latency_ms',
                ]),
                'node_address_history' => $this->tableRows('node_address_history', $subject['node_ids'], [
                    'id', 'node_id', 'ip_address', 'request_type', 'observed_at',
                ]),
                'telemetry_probes' => $this->tableRows('iicp_telemetry_probes', $subject['node_ids'], [
                    'id', 'node_id', 'run_id', 'probe_id', 'probe_type', 'test_id', 'level', 'passed', 'latency_ms', 'probed_at',
                ]),
                'proxy_telemetry' => $this->tableRows('proxy_telemetry', $subject['node_ids'], [
                    'id', 'node_id', 'proxy_node_id', 'time_bucket', 'latency_ms_observed', 'tokens_observed', 'status', 'qos_advertised', 'qos_met',
                ]),
                'node_events' => $this->exportNodeEvents($subject['node_ids']),
                'data_subject_actions' => $this->exportPriorActions($subject['subject_hash']),
            ],
        ];
    }

    /** @param array<string,string|null> $selector */
    public function restrict(array $selector, string $trackingId, bool $dryRun = false): array
    {
        return $this->apply($selector, $trackingId, 'restrict', $dryRun);
    }

    /** @param array<string,string|null> $selector */
    public function anonymize(array $selector, string $trackingId, bool $dryRun = false): array
    {
        return $this->apply($selector, $trackingId, 'anonymize', $dryRun);
    }

    /** @param array<string,string|null> $selector */
    private function apply(array $selector, string $trackingId, string $action, bool $dryRun): array
    {
        if ($trackingId === '') {
            throw new InvalidArgumentException('tracking_id is required for mutating DSR actions');
        }
        $subject = $this->resolveSubject($selector);
        $counts = $this->affectedCounts($subject['node_ids'], $subject['operator_pubkeys']);

        if ($dryRun) {
            return [
                'action' => $action,
                'dry_run' => true,
                'tracking_id' => $trackingId,
                'selector' => $subject['redacted_selector'],
                'subject_hash' => $subject['subject_hash'],
                'affected_counts' => $counts,
                'retention_reason' => self::RETENTION_REASON,
            ];
        }

        DB::transaction(function () use ($subject, $trackingId, $action, &$counts): void {
            $this->applyRestrictionToNodes($subject['node_ids']);
            $this->applyRestrictionToOperators($subject['operator_pubkeys']);

            if ($action === 'anonymize') {
                $this->applyAnonymizationToNodes($subject['node_ids'], $trackingId);
                $this->applyAnonymizationToOperators($subject['operator_pubkeys'], $trackingId);
                $counts['deleted_node_address_history'] = $this->deleteByNodeIds('node_address_history', $subject['node_ids']);
                $counts['deleted_telemetry_probes'] = $this->deleteByNodeIds('iicp_telemetry_probes', $subject['node_ids']);
                $counts['deleted_proxy_telemetry'] = $this->deleteByNodeIds('proxy_telemetry', $subject['node_ids']);
            }

            DataSubjectAction::create([
                'tracking_id' => $trackingId,
                'action' => $action,
                'subject_hash' => $subject['subject_hash'],
                'selector' => $subject['redacted_selector'],
                'affected_counts' => $counts,
                'retention_reason' => self::RETENTION_REASON,
                'applied_at' => now(),
            ]);
        });

        return [
            'action' => $action,
            'dry_run' => false,
            'tracking_id' => $trackingId,
            'selector' => $subject['redacted_selector'],
            'subject_hash' => $subject['subject_hash'],
            'affected_counts' => $counts,
            'retention_reason' => self::RETENTION_REASON,
        ];
    }

    /** @param array<string,string|null> $selector */
    private function resolveSubject(array $selector): array
    {
        $selector = array_filter($selector, fn ($value) => $value !== null && $value !== '');
        if ($selector === []) {
            throw new InvalidArgumentException('At least one selector is required: node_id, endpoint, operator_pubkey, or operator_fingerprint');
        }

        $operatorPubkeys = collect();
        if (isset($selector['operator_pubkey'])) {
            $operatorPubkeys->push((string) $selector['operator_pubkey']);
        }
        if (isset($selector['operator_fingerprint'])) {
            $fingerprint = (string) $selector['operator_fingerprint'];
            Operator::query()->select(['operator_pubkey'])->cursor()
                ->filter(fn (Operator $operator) => Operator::publicFingerprint($operator->operator_pubkey) === $fingerprint)
                ->each(fn (Operator $operator) => $operatorPubkeys->push($operator->operator_pubkey));
        }

        $nodesQuery = Node::query();
        $nodesQuery->where(function ($q) use ($selector, $operatorPubkeys): void {
            $hasClause = false;
            if (isset($selector['node_id'])) {
                $q->orWhere('id', (string) $selector['node_id']);
                $hasClause = true;
            }
            if (isset($selector['endpoint'])) {
                $q->orWhere('endpoint', (string) $selector['endpoint']);
                $hasClause = true;
            }
            if ($operatorPubkeys->isNotEmpty()) {
                $q->orWhereIn('operator_pubkey', $operatorPubkeys->unique()->values()->all());
                $hasClause = true;
            }
            if (! $hasClause) {
                $q->whereRaw('1 = 0');
            }
        });

        /** @var EloquentCollection<int,Node> $nodes */
        $nodes = $nodesQuery->get();
        $operatorPubkeys = $operatorPubkeys
            ->merge($nodes->pluck('operator_pubkey')->filter())
            ->unique()
            ->values();

        /** @var EloquentCollection<int,Operator> $operators */
        $operators = $operatorPubkeys->isEmpty()
            ? new EloquentCollection
            : Operator::whereIn('operator_pubkey', $operatorPubkeys->all())->get();

        $nodeIds = $nodes->pluck('id')->unique()->values();
        if ($nodeIds->isEmpty() && $operators->isEmpty()) {
            throw new InvalidArgumentException('No directory records matched the DSR selector');
        }

        $redactedSelector = $this->redactSelector($selector);

        return [
            'nodes' => $nodes,
            'operators' => $operators,
            'node_ids' => $nodeIds,
            'operator_pubkeys' => $operatorPubkeys,
            'redacted_selector' => $redactedSelector,
            'subject_hash' => hash('sha256', json_encode($redactedSelector, JSON_UNESCAPED_SLASHES)),
        ];
    }

    /** @param array<string,string> $selector */
    private function redactSelector(array $selector): array
    {
        $redacted = [];
        foreach ($selector as $key => $value) {
            $value = (string) $value;
            $redacted[$key] = match ($key) {
                'operator_pubkey' => [
                    'sha256' => hash('sha256', $value),
                    'fingerprint' => Operator::publicFingerprint($value),
                ],
                'endpoint' => [
                    'sha256' => hash('sha256', $value),
                    'host' => parse_url($value, PHP_URL_HOST),
                ],
                default => $value,
            };
        }

        return $redacted;
    }

    /** @param EloquentCollection<int,Operator> $operators */
    private function exportOperators(EloquentCollection $operators): array
    {
        return $operators->map(fn (Operator $operator) => [
            'id' => $operator->id,
            'operator_fingerprint' => Operator::publicFingerprint($operator->operator_pubkey),
            'operator_pubkey_sha256' => hash('sha256', $operator->operator_pubkey),
            'display_name' => $operator->display_name,
            'attested_created_at' => $operator->attested_created_at,
            'operator_integrity_hash' => $operator->operator_integrity_hash,
            'first_seen_ms' => $operator->first_seen_ms,
            'ordinal' => $operator->ordinal,
            'tier' => $operator->tier,
            'badge' => $operator->badge,
            'provenance' => $operator->provenance,
            'terms_version' => $operator->terms_version,
            'terms_accepted_at' => $this->date($operator->terms_accepted_at),
            'dpa_version' => $operator->dpa_version,
            'dpa_accepted_at' => $this->date($operator->dpa_accepted_at),
            'acceptance_method' => $operator->acceptance_method,
            'created_at' => $this->date($operator->created_at),
            'updated_at' => $this->date($operator->updated_at),
        ])->values()->all();
    }

    /** @param EloquentCollection<int,Node> $nodes */
    private function exportNodes(EloquentCollection $nodes): array
    {
        return $nodes->map(fn (Node $node) => [
            'id' => $node->id,
            'endpoint' => $node->endpoint,
            'region' => $node->region,
            'status' => $node->status,
            'available' => (bool) $node->available,
            'public_listing' => (bool) $node->public_listing,
            'operator_url' => $node->operator_url,
            'operator_contact' => $node->operator_contact,
            'operator_fingerprint' => $node->operator_pubkey ? Operator::publicFingerprint($node->operator_pubkey) : null,
            'operator_verified' => (bool) $node->operator_verified,
            'operator_trust_tier' => $node->operator_trust_tier,
            'observed_source_ip' => $node->observed_source_ip,
            'last_seen' => $this->date($node->last_seen),
            'dormant_since' => $this->date($node->dormant_since),
            'policy_manifest' => is_array($node->policy_manifest) ? $this->redactPayload($node->policy_manifest) : null,
            'sdk_language' => $node->sdk_language,
            'implementation_name' => $node->implementation_name,
            'implementation_version' => $node->implementation_version,
            'sdk_compatibility_version' => $node->effectiveSdkCompatibilityVersion(),
            'sdk_version' => $node->effectiveSdkCompatibilityVersion(),
            'secret_fields_present' => [
                'node_token_hash' => $node->node_token_hash !== null,
                'proxy_token_hash' => $node->proxy_token_hash !== null,
                'node_hmac_key' => $node->node_hmac_key !== null,
            ],
        ])->values()->all();
    }

    /** @param Collection<int,string> $nodeIds @param array<int,string> $columns */
    private function tableRows(string $table, Collection $nodeIds, array $columns): array
    {
        if ($nodeIds->isEmpty() || ! Schema::hasTable($table)) {
            return [];
        }

        return DB::table($table)
            ->whereIn('node_id', $nodeIds->all())
            ->orderBy('node_id')
            ->limit(500)
            ->get($columns)
            ->map(function ($row) {
                $record = (array) $row;
                foreach (['models', 'input_modalities'] as $jsonColumn) {
                    if (isset($record[$jsonColumn]) && is_string($record[$jsonColumn])) {
                        $decoded = json_decode($record[$jsonColumn], true);
                        if (json_last_error() === JSON_ERROR_NONE) {
                            $record[$jsonColumn] = $decoded;
                        }
                    }
                }

                return $record;
            })
            ->all();
    }

    /** @param Collection<int,string> $nodeIds */
    private function exportNodeEvents(Collection $nodeIds): array
    {
        if ($nodeIds->isEmpty()) {
            return [];
        }

        return DB::table('node_events')
            ->whereIn('node_id', $nodeIds->all())
            ->orderBy('seq')
            ->limit(500)
            ->get(['event_id', 'seq', 'event_type', 'node_id', 'ts_ms', 'payload', 'prev_hash', 'signature', 'created_at'])
            ->map(function ($row) {
                $payload = json_decode((string) $row->payload, true);

                return [
                    'event_id' => $row->event_id,
                    'seq' => $row->seq,
                    'event_type' => $row->event_type,
                    'node_id' => $row->node_id,
                    'ts_ms' => $row->ts_ms,
                    'payload' => $this->redactPayload(is_array($payload) ? $payload : []),
                    'prev_hash' => $row->prev_hash,
                    'signature_present' => $row->signature !== null,
                    'created_at' => $row->created_at,
                ];
            })
            ->all();
    }

    private function exportPriorActions(string $subjectHash): array
    {
        if (! Schema::hasTable('data_subject_actions')) {
            return [];
        }

        return DataSubjectAction::query()
            ->where('subject_hash', $subjectHash)
            ->orderBy('id')
            ->get(['tracking_id', 'action', 'subject_hash', 'affected_counts', 'retention_reason', 'applied_at'])
            ->map(fn (DataSubjectAction $action) => [
                'tracking_id' => $action->tracking_id,
                'action' => $action->action,
                'subject_hash' => $action->subject_hash,
                'affected_counts' => $action->affected_counts,
                'retention_reason' => $action->retention_reason,
                'applied_at' => $this->date($action->applied_at),
            ])
            ->all();
    }

    private function redactPayload(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (in_array($key, ['node_token', 'node_token_hash', 'proxy_token', 'proxy_token_hash', 'node_hmac_key', 'current_node_token'], true)) {
                $payload[$key] = '[redacted]';

                continue;
            }

            if (in_array($key, ['operator_pubkey', 'public_key', 'did_public_key'], true) && is_string($value)) {
                if ($key === 'operator_pubkey') {
                    $payload['operator_fingerprint'] = Operator::publicFingerprint($value);
                } else {
                    $payload["{$key}_sha256"] = hash('sha256', $value);
                }
                $payload[$key] = '[redacted]';

                continue;
            }

            if (is_array($value)) {
                $payload[$key] = $this->redactPayload($value);
            }
        }

        return $payload;
    }

    /** @param Collection<int,string> $nodeIds @param Collection<int,string> $operatorPubkeys */
    private function affectedCounts(Collection $nodeIds, Collection $operatorPubkeys): array
    {
        return [
            'nodes' => $nodeIds->count(),
            'operators' => $operatorPubkeys->count(),
            'credits' => $this->countByNodeIds('credits', $nodeIds),
            'credit_transactions' => $this->countByNodeIds('credit_transactions', $nodeIds),
            'node_events_retained' => $this->countByNodeIds('node_events', $nodeIds),
            'node_address_history' => $this->countByNodeIds('node_address_history', $nodeIds),
            'telemetry_probes' => $this->countByNodeIds('iicp_telemetry_probes', $nodeIds),
            'proxy_telemetry' => $this->countByNodeIds('proxy_telemetry', $nodeIds),
        ];
    }

    /** @param Collection<int,string> $nodeIds */
    private function countByNodeIds(string $table, Collection $nodeIds): int
    {
        if ($nodeIds->isEmpty() || ! Schema::hasTable($table)) {
            return 0;
        }

        return (int) DB::table($table)->whereIn('node_id', $nodeIds->all())->count();
    }

    /** @param Collection<int,string> $nodeIds */
    private function deleteByNodeIds(string $table, Collection $nodeIds): int
    {
        if ($nodeIds->isEmpty() || ! Schema::hasTable($table)) {
            return 0;
        }

        return (int) DB::table($table)->whereIn('node_id', $nodeIds->all())->delete();
    }

    /** @param Collection<int,string> $nodeIds */
    private function applyRestrictionToNodes(Collection $nodeIds): void
    {
        if ($nodeIds->isEmpty()) {
            return;
        }

        Node::whereIn('id', $nodeIds->all())->update([
            'available' => false,
            'public_reachable' => false,
            'public_listing' => false,
            'operator_url' => null,
            'operator_contact' => null,
            'status' => 'archived',
            'dormant_since' => now(),
            'transport_endpoint' => null,
            'endpoint_verified_dead_at' => now(),
        ]);
    }

    /** @param Collection<int,string> $operatorPubkeys */
    private function applyRestrictionToOperators(Collection $operatorPubkeys): void
    {
        foreach ($operatorPubkeys as $pubkey) {
            Operator::where('operator_pubkey', $pubkey)->first()?->update([
                'identity_status' => 'restricted',
                'display_name' => null,
                'attested_created_at' => null,
                'operator_integrity_hash' => null,
                'terms_version' => null,
                'terms_accepted_at' => null,
                'dpa_version' => null,
                'dpa_accepted_at' => null,
                'acceptance_method' => null,
                'acceptance_nonce_sha256' => null,
                'tier' => null,
                'badge' => null,
                'provenance' => ['dsr' => 'restricted'],
            ]);
        }
    }

    /** @param Collection<int,string> $nodeIds */
    private function applyAnonymizationToNodes(Collection $nodeIds, string $trackingId): void
    {
        foreach ($nodeIds as $nodeId) {
            Node::where('id', $nodeId)->update([
                'endpoint' => 'https://dsr-anonymized.invalid/node-'.substr($nodeId, 0, 8),
                'observed_source_ip' => null,
                'operator_pubkey' => null,
                'operator_verified' => false,
                'operator_trust_tier' => null,
                'identity_key' => hash('sha256', "dsr:{$trackingId}:{$nodeId}"),
                'liveness_challenge' => null,
                'liveness_verified_at' => null,
                'policy_manifest' => null,
                'cx_public_key' => null,
                'gossip_public_key' => null,
                'node_token_hash' => password_hash(Str::random(40), PASSWORD_BCRYPT),
                'proxy_token_hash' => password_hash(Str::random(40), PASSWORD_BCRYPT),
                'node_hmac_key' => hash('sha256', Str::random(40)),
            ]);
        }
    }

    /** @param Collection<int,string> $operatorPubkeys */
    private function applyAnonymizationToOperators(Collection $operatorPubkeys, string $trackingId): void
    {
        foreach ($operatorPubkeys as $pubkey) {
            Operator::where('operator_pubkey', $pubkey)->update([
                'operator_pubkey' => 'dsr_'.substr(hash('sha256', "operator:{$trackingId}:{$pubkey}"), 0, 60),
                'display_name' => null,
                'attested_created_at' => null,
                'operator_integrity_hash' => null,
                'terms_version' => null,
                'terms_accepted_at' => null,
                'dpa_version' => null,
                'dpa_accepted_at' => null,
                'acceptance_method' => null,
                'acceptance_nonce_sha256' => null,
                'tier' => null,
                'badge' => null,
                'provenance' => ['dsr' => 'anonymized'],
            ]);
        }
    }

    private function date(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toIso8601String();
        }

        return $value === null ? null : (string) $value;
    }
}
