#!/bin/bash
set -uo pipefail

# Execute Playwright tests and display the HTML report from the uitest/ project.
repo_root="$(cd "$(dirname "$0")" && pwd)"
cd "$repo_root/uitest"

test_exit=0
if ! npm test; then
  test_exit=$?
fi

# Print report location instead of launching the long-lived viewer so the
# script exits promptly. Users can run `npx playwright show-report` manually
# whenever they want to inspect it interactively.
report_dir="$repo_root/uitest/playwright-report"
if [ -d "$report_dir" ]; then
  echo "Playwright HTML report generated at: $report_dir"
  echo "Open it later with: (cd uitest && npx playwright show-report)"
fi

exit "$test_exit"
