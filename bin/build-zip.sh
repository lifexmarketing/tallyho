#!/usr/bin/env bash
# Builds dist/tallyho.zip with a top-level tallyho/
# folder, which is what the WordPress upgrader expects. Files listed in
# .distignore are left out.
set -euo pipefail

SLUG="tallyho"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BUILD="$(mktemp -d)"
trap 'rm -rf "$BUILD"' EXIT

mkdir -p "$BUILD/$SLUG" "$ROOT/dist"
tar -C "$ROOT" -X "$ROOT/.distignore" -cf - . | tar -C "$BUILD/$SLUG" -xf -

rm -f "$ROOT/dist/$SLUG.zip"
(cd "$BUILD" && zip -qr "$ROOT/dist/$SLUG.zip" "$SLUG")
echo "Built dist/$SLUG.zip"
unzip -l "$ROOT/dist/$SLUG.zip"
