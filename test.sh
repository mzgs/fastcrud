#!/bin/bash
set -uo pipefail

# Execute Playwright tests and display the HTML report from the uitest/ project.
repo_root="$(cd "$(dirname "$0")" && pwd)"
cd "$repo_root/uitest"

headed_run=false
video_mode=""

while (($#)); do
  case "$1" in
    --headed|--show)
      headed_run=true
      ;;
    --video|--videos)
      video_mode="on"
      ;;
    --)
      shift
      break
      ;;
    *)
      echo "Unknown option: $1" >&2
      echo "Usage: $0 [--show] [--video]" >&2
      exit 2
      ;;
  esac
  shift
done

command=(npm test)
if $headed_run; then
  command=(npm run test:headed)
fi

if [ "$#" -gt 0 ]; then
  command+=(-- "$@")
fi

if [ -n "$video_mode" ]; then
  export UI_VIDEO_MODE="$video_mode"
else
  unset UI_VIDEO_MODE
fi

test_exit=0
if ! "${command[@]}"; then
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
