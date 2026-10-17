#!/usr/bin/env bash
# Fails unless the plugin header Version and TALLYHO_VERSION both equal $1.
set -euo pipefail

WANT="${1:?usage: bin/check-version.sh 1.2.3}"
FILE="$(cd "$(dirname "$0")/.." && pwd)/tallyho.php"

HEADER="$(sed -n 's/^ \* Version:[[:space:]]*//p' "$FILE" | tr -d '[:space:]')"
CONST="$(sed -n "s/^define( 'TALLYHO_VERSION', '\([^']*\)' );/\1/p" "$FILE")"

echo "tag=$WANT header=$HEADER constant=$CONST"
if [ "$HEADER" != "$WANT" ] || [ "$CONST" != "$WANT" ]; then
  echo "Version mismatch. Update the Version header and TALLYHO_VERSION to $WANT before tagging." >&2
  exit 1
fi
