#!/usr/bin/env bash
set -euo pipefail
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
RELEASES_DIR="$ROOT_DIR/build/releases"
mkdir -p "$RELEASES_DIR"

stage="$(mktemp -d)"
cp "$ROOT_DIR/install.xml" "$stage/install.xml"
cp -R "$ROOT_DIR/upload" "$stage/upload"
(cd "$stage" && zip -qr "$RELEASES_DIR/eleads-opencart-3.x.ocmod.zip" install.xml upload)
rm -rf "$stage"

echo "Built: $RELEASES_DIR/eleads-opencart-3.x.ocmod.zip"
