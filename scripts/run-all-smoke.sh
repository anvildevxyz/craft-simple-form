#!/usr/bin/env bash
# Run ALL simple-form smoke tests sequentially:
#  1. Codeception functional suite (DDEV)
#  2. Playwright browser scenarios (host → ddev.site)
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
PLUGIN="$ROOT/plugins/simple-form"
BROWSER="$PLUGIN/scripts/browser-smoke"

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "1/2  Codeception functional smoke"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
ddev exec -d /var/www/html/plugins/simple-form 'composer test:smoke'

echo
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "2/2  Playwright browser smoke"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
cd "$BROWSER"
if [[ ! -d node_modules ]]; then
  npm install --no-fund --no-audit
  npx playwright install chromium
fi
npm run run

echo
echo "All smoke layers finished."
