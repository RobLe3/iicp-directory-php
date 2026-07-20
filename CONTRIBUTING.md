# Contributing

Changes must preserve the control-plane boundary: registration, discovery and
route authorization belong here; task execution does not.

Before opening a pull request, run:

```bash
composer install --no-interaction --prefer-dist
php artisan test --compact
python3 scripts/check_seed_parity.py \
  --manifest parity/seed-manifest-v1.10.76.1.json \
  --php-dir .
```

Protocol changes require a corresponding proposal in the IICP specification
repository. Do not include production configuration or real operator data.
