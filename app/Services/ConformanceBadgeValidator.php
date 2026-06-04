<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Conformance badge submission validator — ConformanceController gate (BADGE-01..BADGE-05).
 *
 * WHY a dedicated validator class rather than inline Laravel validation: badge validation
 * requires cross-field logic (genesis-verified mode vs self-attested mode have different
 * required fields), tier/component/mode allow-lists, and 90-day expiry enforcement. Inline
 * validation with Laravel's built-in Validator would require custom rules for all of these;
 * a class makes the full contract readable in one place.
 *
 * WHY MAX_EXPIRY_DAYS = 91: the spec says badges expire after 90 days. A one-day grace
 * period (91 days) absorbs clock skew between the badge issuer and the directory server.
 * BADGE-03 normative text: "expire: ≤ 90 days from passed_at, allow ≤ 1 day over for skew."
 *
 * WHY VALID_TIERS is a constant (not DB-driven): the badge tier list is normative and part
 * of the spec — it changes only with a spec version bump, not with operator data. DB-driven
 * tier lists would allow rogue directory operators to create arbitrary badge tiers not
 * recognized by other directories (anti-pattern for a federated system).
 *
 * Spec: spec/iicp-recognition.md (BADGE-01..BADGE-05). ADR: ADR-013 (federation prereq).
 */
class ConformanceBadgeValidator
{
    private const VALID_TIERS = [
        'iicp:core:v1',
        'iicp:mesh:v1',
        'iicp:sdk:v1',
        'iicp:federated:v1',
        'iicp:full:v1',
    ];

    private const VALID_COMPONENTS = ['adapter', 'proxy', 'sdk', 'replica'];

    private const VALID_MODES = ['genesis-verified', 'self-attested'];

    private const MAX_EXPIRY_DAYS = 91;

    public static function validTiers(): array
    {
        return self::VALID_TIERS;
    }

    public function validate(array $data, string $mode = 'self-attested'): array
    {
        $errors = $this->validateRequiredFields($data, $mode);
        if (! empty($errors)) {
            return $errors;
        }

        $errors = array_merge($errors, $this->validateFormats($data));

        try {
            $passedAt = Carbon::parse($data['passed_at']);
        } catch (\Exception $e) {
            $errors['passed_at'] = 'Must be a valid ISO-8601 datetime.';

            return $errors;
        }

        if (! empty($data['expires_at'])) {
            $errors = array_merge($errors, $this->validateExpiry($data['expires_at'], $passedAt));
        }

        return $errors;
    }

    private function validateRequiredFields(array $data, string $mode): array
    {
        $errors = [];
        $required = ['badge_id', 'tier', 'subject_did', 'subject_component',
            'suite_version', 'passed_at', 'test_results_url', 'issuer_did'];

        if ($mode !== 'genesis-verified') {
            $required[] = 'sig';
        }

        foreach ($required as $field) {
            if (empty($data[$field])) {
                $errors[$field] = 'Required field missing.';
            }
        }

        return $errors;
    }

    private function validateFormats(array $data): array
    {
        $errors = [];

        if (! Str::isUuid($data['badge_id'])) {
            $errors['badge_id'] = 'Must be a valid UUID v4.';
        }

        if (! in_array($data['tier'], self::VALID_TIERS, true)) {
            $errors['tier'] = 'Unknown tier. Valid: '.implode(', ', self::VALID_TIERS);
        }

        if (! preg_match('/^did:[a-z]+:.+$/', $data['subject_did'])) {
            $errors['subject_did'] = 'Must be a valid DID (did:method:id).';
        }

        if (! in_array($data['subject_component'], self::VALID_COMPONENTS, true)) {
            $errors['subject_component'] = 'Must be one of: '.implode(', ', self::VALID_COMPONENTS);
        }

        if (! preg_match('/^\d+\.\d+\.\d+$/', $data['suite_version'])) {
            $errors['suite_version'] = 'Must be semver (MAJOR.MINOR.PATCH).';
        }

        if (! filter_var($data['test_results_url'], FILTER_VALIDATE_URL)) {
            $errors['test_results_url'] = 'Must be a valid URL.';
        }

        if (! preg_match('/^did:[a-z]+:.+$/', $data['issuer_did'])) {
            $errors['issuer_did'] = 'Must be a valid DID.';
        }

        if (! empty($data['verification_mode']) &&
            ! in_array($data['verification_mode'], self::VALID_MODES, true)) {
            $errors['verification_mode'] = 'Must be genesis-verified or self-attested.';
        }

        return $errors;
    }

    private function validateExpiry(string $expiresAtRaw, Carbon $passedAt): array
    {
        $errors = [];
        try {
            $expiresAt = Carbon::parse($expiresAtRaw);
            $diffDays = $passedAt->diffInDays($expiresAt, false);
            if ($diffDays < 0) {
                $errors['expires_at'] = 'expires_at must be after passed_at.';
            } elseif ($diffDays > self::MAX_EXPIRY_DAYS) {
                $errors['expires_at'] = 'Expiry must be within 91 days of passed_at (BADGE-03).';
            }
        } catch (\Exception $e) {
            $errors['expires_at'] = 'Must be a valid ISO-8601 datetime.';
        }

        return $errors;
    }
}
