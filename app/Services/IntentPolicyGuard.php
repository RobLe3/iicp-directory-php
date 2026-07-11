<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Services;

/**
 * EU AI Act intent-risk guard for public-mesh routing.
 *
 * This is a technical compliance-readiness classifier, not legal advice and not
 * prompt-content moderation. It mirrors the shared taxonomy fixture in
 * spec/intent-risk-taxonomy.json so direct directory callers cannot advertise or
 * discover obvious prohibited/high-risk intent families through the public mesh.
 */
class IntentPolicyGuard
{
    public const REFUSAL_CODE = 'IICP-POLICY-001';

    public const CATEGORY_PROHIBITED = 'prohibited';

    public const CATEGORY_HIGH_RISK = 'high_risk';

    public const CATEGORY_TRANSPARENCY_RISK = 'transparency_risk';

    public const CATEGORY_MINIMAL_OR_GENERAL = 'minimal_or_general';

    /** @var array<int,array{category:string,rule_id:string,label:string,fragments:array<int,string>}> */
    private const INTENT_RISK_RULES = [
        ['category' => self::CATEGORY_PROHIBITED, 'rule_id' => 'eu-ai-act-social-scoring', 'label' => 'social scoring', 'fragments' => ['social-scoring', 'social_scoring', 'social:scoring']],
        ['category' => self::CATEGORY_PROHIBITED, 'rule_id' => 'eu-ai-act-criminal-risk', 'label' => 'individual criminal risk prediction', 'fragments' => ['criminal-risk', 'criminal_risk', 'criminal:risk', 'predict-crime']],
        ['category' => self::CATEGORY_PROHIBITED, 'rule_id' => 'eu-ai-act-workplace-education-emotion', 'label' => 'workplace or education emotion recognition', 'fragments' => ['emotion:workplace', 'emotion:education', 'workplace-monitoring', 'education-monitoring', 'worker-monitoring']],
        ['category' => self::CATEGORY_PROHIBITED, 'rule_id' => 'eu-ai-act-protected-trait-biometric', 'label' => 'biometric protected-trait classification', 'fragments' => ['protected-trait', 'protected_trait', 'biometric:protected']],
        ['category' => self::CATEGORY_PROHIBITED, 'rule_id' => 'eu-ai-act-untargeted-face-scraping', 'label' => 'untargeted facial image scraping for recognition databases', 'fragments' => ['untargeted-scraping', 'untargeted_scraping', 'face-scraping', 'facial-scraping']],
        ['category' => self::CATEGORY_PROHIBITED, 'rule_id' => 'eu-ai-act-realtime-remote-biometric-id', 'label' => 'real-time remote biometric identification', 'fragments' => ['remote-biometric:realtime', 'realtime-remote-biometric', 'real-time-remote-biometric']],
        ['category' => self::CATEGORY_PROHIBITED, 'rule_id' => 'eu-ai-act-nonconsensual-sexual-deepfake', 'label' => 'non-consensual sexual deepfake or CSAM generation', 'fragments' => ['nonconsensual-sexual', 'non-consensual-sexual', 'child-sexual-abuse', 'csam']],
        ['category' => self::CATEGORY_HIGH_RISK, 'rule_id' => 'eu-ai-act-employment-workforce', 'label' => 'employment, recruitment, worker management or worker monitoring decision', 'fragments' => ['employment:hiring', 'employment:screen', 'employment:rank', 'recruitment:decision', 'workforce:decision', 'worker-management', 'worker:performance', 'worker:discipline']],
        ['category' => self::CATEGORY_HIGH_RISK, 'rule_id' => 'eu-ai-act-education-admission-grading', 'label' => 'education admission, assessment or grading decision', 'fragments' => ['education:admission', 'education:grading', 'education:grade', 'student:admission', 'student:assess', 'exam-grading']],
        ['category' => self::CATEGORY_HIGH_RISK, 'rule_id' => 'eu-ai-act-credit-essential-services', 'label' => 'credit scoring or essential-services access decision', 'fragments' => ['credit-scoring', 'credit:score', 'credit:decision', 'essential-services', 'benefits:eligibility', 'public-benefit:eligibility']],
        ['category' => self::CATEGORY_HIGH_RISK, 'rule_id' => 'eu-ai-act-law-enforcement-border-justice', 'label' => 'law enforcement, migration, asylum, border, justice or democratic-process decision', 'fragments' => ['law-enforcement', 'law_enforcement', 'migration:decision', 'asylum:decision', 'border-control', 'justice:decision', 'democratic-process', 'election:decision']],
        ['category' => self::CATEGORY_HIGH_RISK, 'rule_id' => 'eu-ai-act-healthcare-critical-infrastructure', 'label' => 'healthcare decision or critical-infrastructure safety component', 'fragments' => ['healthcare:decision', 'medical:diagnosis', 'medical:triage', 'clinical:decision', 'critical-infrastructure', 'grid:stabilize', 'hospital:surge-capacity']],
        ['category' => self::CATEGORY_HIGH_RISK, 'rule_id' => 'eu-ai-act-physical-world-control', 'label' => 'robotics, drone, IoT or physical-world control', 'fragments' => ['robotics:control', 'robotics:fleet', 'drone:control', 'drone:search', 'iot:actuate', 'physical-world', 'system_control']],
        ['category' => self::CATEGORY_TRANSPARENCY_RISK, 'rule_id' => 'eu-ai-act-ai-interaction', 'label' => 'AI interaction or generated content notice required', 'fragments' => ['chatbot', 'ai-assistant', 'synthetic-media', 'deepfake:labelled', 'content:generate-public', 'creative:generate']],
    ];

    /**
     * @return array{category:string,rule_id:string|null,label:string|null}
     */
    public function classify(string $intent): array
    {
        $normalized = strtolower(trim($intent));

        foreach (self::INTENT_RISK_RULES as $rule) {
            foreach ($rule['fragments'] as $fragment) {
                if (str_contains($normalized, $fragment)) {
                    return [
                        'category' => $rule['category'],
                        'rule_id' => $rule['rule_id'],
                        'label' => $rule['label'],
                    ];
                }
            }
        }

        return [
            'category' => self::CATEGORY_MINIMAL_OR_GENERAL,
            'rule_id' => null,
            'label' => null,
        ];
    }

    public function prohibitedIntentReason(string $intent): ?string
    {
        $classification = $this->classify($intent);

        return $classification['category'] === self::CATEGORY_PROHIBITED
            ? sprintf('%s (%s)', $classification['label'], $classification['rule_id'])
            : null;
    }

    public function refusalMessage(string $intent): ?string
    {
        $classification = $this->classify($intent);
        if (! in_array($classification['category'], [self::CATEGORY_PROHIBITED, self::CATEGORY_HIGH_RISK], true)) {
            return null;
        }

        return sprintf(
            'Intent refused by IICP directory policy before discovery/routing: %s (%s, %s). Use a lawful, documented, human-reviewed compliance path outside the public mesh for restricted/high-risk workflows.',
            $classification['label'],
            $classification['rule_id'],
            $classification['category'],
        );
    }

    /**
     * @return array<int,array{category:string,rule_id:string,label:string,fragments:array<int,string>}>
     */
    public function rules(): array
    {
        return self::INTENT_RISK_RULES;
    }
}
