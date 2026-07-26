# Contributing

Changes must preserve the control-plane boundary: registration, discovery and
route authorization belong here; task execution does not.

Before opening a pull request, run:

```bash
composer validate
composer install --no-interaction --prefer-dist
composer audit --locked
php artisan test --compact
python3 scripts/check_seed_parity.py \
  --manifest parity/seed-manifest-v1.10.76.2.json \
  --php-dir . \
  --git-revision c489d4e02a636b337ba4237f8543f83675162db0
```

Protocol changes require a corresponding proposal in the IICP specification
repository. Do not include production configuration or real operator data.
Follow `CODE_OF_CONDUCT.md` in all project interactions.
