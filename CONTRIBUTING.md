# Contributing

Changes must preserve the control-plane boundary: registration, discovery and
route authorization belong here; task execution does not.

Use this repository's issue forms for reproducible PHP implementation defects
and implementation-specific proposals. Use the public IICP specification
repository for protocol or cross-component proposals, the IICP forum for
open-ended discussion, and GitHub's private security-advisory form for
vulnerabilities. Do not include credentials, production topology, task
payloads, operator records or personal data in public issues.

Participation does not confer protocol authority. Decisions and objections on
public proposals are recorded in their public issue or pull request under the
current founder-led governance process.

Before opening a pull request, run:

```bash
composer validate
composer install --no-interaction --prefer-dist
composer audit --locked
composer quality:static
composer quality:format
python3 scripts/check_semgrep_suppressions.py
semgrep scan --config .semgrep/php-security.yml --severity ERROR --error \
  app routes config database
php artisan test --compact
python3 scripts/verify_release.py --version "$(cat VERSION)" --allow-untagged
python3 scripts/check_seed_parity.py \
  --manifest parity/seed-manifest-v1.10.76.2.json \
  --php-dir . \
  --git-revision c489d4e02a636b337ba4237f8543f83675162db0
```

Semgrep CE must be version `1.164.0`. See `docs/QUALITY_GATES.md` for exact
installation, baseline, severity, suppression, and dependency-update policy.

Protocol changes require a corresponding proposal in the IICP specification
repository. Do not include production configuration or real operator data.
Follow `CODE_OF_CONDUCT.md` in all project interactions.

Only the tag-triggered release workflow may publish release assets. Do not
move a published tag or upload replacement artifacts.
