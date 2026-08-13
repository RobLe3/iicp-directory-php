#!/usr/bin/env bash
# SPDX-License-Identifier: Apache-2.0
set -euo pipefail

output="${1:-/tmp/iicp-registration-limit-measurement.json}"
if [[ "$output" != /* ]]; then
  echo "ERROR: output path must be absolute" >&2
  exit 2
fi
rm -f "$output"
IICP_REGISTRATION_MEASUREMENT_OUTPUT="$output" \
  php artisan test --filter RegistrationLimitMeasurementTest
python3 scripts/check_registration_limit_measurement.py "$output"
echo "registration-limit measurement written to $output"
