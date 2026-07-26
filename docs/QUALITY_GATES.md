# PHP quality gates

These checks complement the runtime tests, release/parity verification, and
Sentrux. They do not override a failure in any of those authoritative gates.

## Reproduce locally

Use PHP 8.3 and the locked Composer dependencies, then run:

```bash
mkdir -p bootstrap/cache storage/framework/cache storage/framework/sessions \
  storage/framework/views storage/logs
composer install --no-interaction --prefer-dist
composer quality:static
composer quality:format
python3 scripts/check_semgrep_suppressions.py
python3 -m pip install --require-virtualenv 'semgrep==1.164.0'
semgrep scan --config .semgrep/php-security.yml --severity ERROR --error \
  app routes config database
```

CI installs Semgrep CE `1.164.0` exactly. Dependency updates must be made by a
reviewed pull request that runs the full suite; do not use mutable remote
Semgrep registry aliases in an authoritative gate.

## Existing PHPStan findings

`phpstan-baseline.neon` is the reviewed initial debt snapshot for issue #13.
It records exact messages, counts, and paths, so new findings fail. The initial
entries are framework-model inference gaps, configuration access findings, and
other pre-existing type debt; they authorize no equivalent new debt.

The reviewed level-5 snapshot contains 100 findings: 34 dynamic Eloquent
property inferences, 27 redundant nullsafe inferences, 18 configuration-cache
`env()` findings, and 21 findings across 12 smaller identifiers. The
configuration findings are actionable debt; the inference findings should be
removed through accurate model/query typing rather than blanket exclusions.

Do not regenerate the baseline to make CI pass. Remove entries when fixing
their underlying findings. Any addition requires a linked issue, a written
rationale, and focused tests where behaviour changes.

## Semgrep severity and suppressions

The checked-in rules are narrow, deterministic, high-confidence PHP security
rules. `ERROR` findings fail CI. A false positive may be suppressed only on the
affected line with the rule ID plus both `reason=...` and `issue=#N` (or a full
GitHub issue URL). `scripts/check_semgrep_suppressions.py` rejects unaccountable
suppressions. Prefer fixing or refining the rule over suppression.

## Coverage and mutation policy

The ordinary parity workflow records application-only Clover coverage and
enforces both the reviewed 90% repository floor and 80% coverage of changed
executable PHP lines. Generated files, dependencies, tests, configuration,
routes, and database scaffolding are outside the `app/` coverage denominator.
The initial supported-CI measurement on 2026-07-26 was 90.40%
(7,636/8,447 statements); the floor was rounded down to 90%. The report is
retained for 14 days.

Targeted mutation testing is scheduled/manual rather than paid by every pull
request. Its four scopes cover JWT/authorization, signed event logging,
credits/economic integrity, and discovery/scoring. Reports are retained for 14
days. A surviving mutant must be classified as an equivalent mutant, a test
gap, or an intentional policy choice before thresholds change. Do not add
assertion-free tests or tests that merely execute lines to inflate a metric.

Coverage and mutation floors are ratchets. Lowering one requires a dedicated
pull request, measured before/after evidence, maintainer review, and an issue
explaining why strengthening tests first is not practical.

The initial calibration measured JWT/authorization at 100% MSI, signed events
at 80.85%, and credits at 44.12% when its 38 timeouts are conservatively
treated as escapes. Their rounded-down starting floors are respectively 100%,
80%, and 40%. The original discovery run time-skipped all mutants because its
covering tests exceeded the default 10-second nominal-test limit; the reviewed
60-second limit and covering-test-only mode must produce a valid non-zero
discovery floor before this lane is merged.
