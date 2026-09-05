#!/bin/bash
# ojs/healthcheck.sh
# Used by both Docker's HEALTHCHECK and Kubernetes probes (via the
# same underlying HTTP check — see k8s/ojs-deployment.yaml).
# OJS doesn't ship a dedicated /health endpoint, so we hit the login
# page, which requires config.inc.php to be valid, the DB connection
# to work, and Apache/PHP to be serving requests — a reasonable proxy
# for "the application is actually up," not just "the process exists."
set -euo pipefail

STATUS=$(curl -s -o /dev/null -w "%{http_code}" http://localhost/index.php/index/login || echo "000")

if [ "$STATUS" = "200" ] || [ "$STATUS" = "302" ]; then
  exit 0
else
  echo "Health check failed with HTTP status: ${STATUS}"
  exit 1
fi
