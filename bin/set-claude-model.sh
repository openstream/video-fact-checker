#!/bin/bash
# Sets the vfc_claude_model WP option from the latest commit's
# "Co-Authored-By: Claude <model>" trailer, so the footer credit
# ("Made by Openstream with WordPress & Claude <model>") stays in sync with the
# model that built the deployed code. Run after each deploy (git pull) on prod.
#
# Usage (from the plugin dir on the server):
#   bash bin/set-claude-model.sh
#
# It uses `sudo -u www-data -- wp` (prod runs WP-CLI as www-data). Adjust WP_CLI
# if your environment differs.

set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$PLUGIN_DIR"

MODEL="$(git log -1 --format='%b' \
  | grep -oiE 'Co-Authored-By: Claude [^<(]*' \
  | head -1 \
  | sed 's/Co-Authored-By: Claude //I; s/ *$//')"

if [ -z "${MODEL}" ]; then
  echo "No 'Co-Authored-By: Claude …' trailer in the latest commit; leaving vfc_claude_model unchanged."
  exit 0
fi

WP_CLI="${WP_CLI:-sudo -u www-data -- wp}"
# WordPress root is three levels up from the plugin dir; can be overridden.
WP_PATH="${WP_PATH:-$(cd "$PLUGIN_DIR/../../.." && pwd)}"

PATH_ARG=""
[ -n "$WP_PATH" ] && PATH_ARG="--path=$WP_PATH"

$WP_CLI $PATH_ARG option update vfc_claude_model "$MODEL"
echo "vfc_claude_model set to: $MODEL"
