#!/bin/bash
# Run wp-cli against a Local (by Flywheel) site using that site's own PHP and
# MySQL socket, so it works from any terminal (no "Open site shell" needed).
#
#   bin/local-wp.sh <local-site-name> <wp-cli args...>
#   bin/local-wp.sh b2c-testing plugin list
#
# The site must be running in Local.
set -euo pipefail

SITE_NAME="${1:?usage: bin/local-wp.sh <local-site-name> <wp-cli args...>}"
shift

LOCAL_DIR="$HOME/Library/Application Support/Local"
IFS=$'\t' read -r SITE_ID SITE_PATH PHP_VERSION < <(python3 - "$LOCAL_DIR/sites.json" "$SITE_NAME" <<'PY'
import json, os, sys
sites = json.load(open(sys.argv[1]))
name = sys.argv[2]
for sid, s in sites.items():
    folder = os.path.basename(os.path.expanduser(s["path"]))
    if name in (s.get("name"), folder, s.get("domain")):
        print(sid, os.path.expanduser(s["path"]), s["services"]["php"]["version"], sep="\t")
        sys.exit(0)
sys.exit("Local site not found: " + name)
PY
)

PHP_BIN=$(ls -d "$LOCAL_DIR"/lightning-services/php-"$PHP_VERSION"*/bin/darwin*/bin/php 2>/dev/null | head -1)
SOCK="$LOCAL_DIR/run/$SITE_ID/mysql/mysqld.sock"
[ -x "$PHP_BIN" ] || { echo "Local PHP $PHP_VERSION not found" >&2; exit 1; }
[ -S "$SOCK" ] || { echo "MySQL socket missing; start the site in Local first" >&2; exit 1; }

WP_PHAR=$(python3 -c 'import os,shutil;print(os.path.realpath(shutil.which("wp") or ""))')
[ -f "$WP_PHAR" ] || { echo "wp-cli not installed (brew install wp-cli)" >&2; exit 1; }

exec "$PHP_BIN" -d "mysqli.default_socket=$SOCK" -d memory_limit=512M \
  "$WP_PHAR" --path="$SITE_PATH/app/public" "$@"
