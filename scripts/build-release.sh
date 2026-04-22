#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_MAIN="$ROOT_DIR/document-center-builder.php"
README_FILE="$ROOT_DIR/readme.txt"
DIST_DIR="$ROOT_DIR/dist"
STAGE_DIR="$DIST_DIR/stage/document-center-builder"

if [[ ! -f "$PLUGIN_MAIN" ]]; then
  echo "Missing plugin main file: $PLUGIN_MAIN" >&2
  exit 1
fi
if [[ ! -f "$README_FILE" ]]; then
  echo "Missing readme file: $README_FILE" >&2
  exit 1
fi

PLUGIN_VERSION="$(awk -F': ' '/^[[:space:]]*\*[[:space:]]*Version:/ {print $2; exit}' "$PLUGIN_MAIN" | tr -d '\r' | sed -E 's/[[:space:]]+$//')"
STABLE_TAG="$(awk -F': ' '/^Stable tag:/ {print $2; exit}' "$README_FILE" | tr -d '\r' | sed -E 's/[[:space:]]+$//')"

if [[ -z "$PLUGIN_VERSION" || -z "$STABLE_TAG" ]]; then
  echo "Version/stable tag could not be detected." >&2
  exit 1
fi

if [[ "$PLUGIN_VERSION" != "$STABLE_TAG" ]]; then
  echo "Version mismatch: plugin=$PLUGIN_VERSION readme=$STABLE_TAG" >&2
  exit 1
fi

if ! grep -Eq "^= ${PLUGIN_VERSION} =" "$README_FILE"; then
  echo "Changelog entry for version ${PLUGIN_VERSION} is missing in readme.txt" >&2
  exit 1
fi

rm -rf "$DIST_DIR"
mkdir -p "$STAGE_DIR"

rsync -a \
  --exclude='.git/' \
  --exclude='.github/' \
  --exclude='.venv/' \
  --exclude='venv/' \
  --exclude='env/' \
  --exclude='node_modules/' \
  --exclude='.DS_Store' \
  --exclude='tests/' \
  --exclude='fixtures/' \
  --exclude='private-fixtures/' \
  --exclude='reports/' \
  --exclude='docs/' \
  --exclude='scripts/' \
  --exclude='dist/' \
  --exclude='*.zip' \
  "$ROOT_DIR/" "$STAGE_DIR/"

OUTPUT_ZIP="$DIST_DIR/document-center-builder-${PLUGIN_VERSION}-release.zip"
(
  cd "$DIST_DIR/stage"
  zip -rq "$OUTPUT_ZIP" "document-center-builder"
)

echo "Release package created: $OUTPUT_ZIP"
