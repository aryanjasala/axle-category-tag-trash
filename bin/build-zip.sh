#!/usr/bin/env bash
# Builds dist/axle-category-tag-trash/ and dist/axle-category-tag-trash.zip from the working tree, leaving out
# everything listed in .distignore. Used by the release and Plugin Check workflows.
set -euo pipefail
cd "$(dirname "$0")/.."

slug="axle-category-tag-trash"
rm -rf dist
mkdir -p "dist/$slug"
rsync -a --exclude-from=.distignore ./ "dist/$slug/"
(cd dist && zip -qr "$slug.zip" "$slug")
echo "dist/$slug.zip"
