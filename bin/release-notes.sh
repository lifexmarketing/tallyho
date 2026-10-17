#!/usr/bin/env bash
# Prints the CHANGELOG.md section for version $1, plus the requirement lines the
# updater reads (Requires at least / Requires PHP / Tested up to).
set -euo pipefail

VERSION="${1:?usage: bin/release-notes.sh 1.2.3}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"

# Match "## [1.2.3]" or "## 1.2.3" exactly (dots literal, no 1.2.30).
awk -v v="$VERSION" '
  BEGIN { gsub(/\./, "\\.", v); pat = "^## \\[?" v "(\\]|[[:space:]]|$)" }
  $0 ~ pat { on = 1; next }
  on && /^## / { exit }
  on { print }
' "$ROOT/CHANGELOG.md"

echo
sed -n 's/^ \* \(Requires at least\|Requires PHP\|Tested up to\):[[:space:]]*\(.*\)$/\1: \2/p' "$ROOT/tallyho.php"
