#!/bin/bash
set -uo pipefail

# Execute Playwright tests and display the HTML report from the uitest/ project.
repo_root="$(cd "$(dirname "$0")" && pwd)"
cd "$repo_root/uitest"

test_exit=0
if ! npm test; then
  test_exit=$?
fi

# Always attempt to open the most recent report for inspection.
npx playwright show-report || true

exit "$test_exit"
