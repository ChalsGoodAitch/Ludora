#!/usr/bin/env sh
set -eu
mkdir -p tests/artifacts
for ludora_suite in domain/run integration/run integration/concurrency integration/inventory integration/shared-inventory integration/configuration integration/security integration/privacy-race integration/lifecycle integration/mail integration/late-policy integration/csv-volume woocommerce/run woocommerce/pricing; do
  ludora_report=$(printf '%s' "$ludora_suite" | tr / -)
  docker compose exec --user www-data -T wordpress php "ludora-tests/$ludora_suite.php" > "tests/artifacts/final-$ludora_report.txt" 2>&1
  printf 'PASS %s\n' "$ludora_suite"
done
