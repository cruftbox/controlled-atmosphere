#!/usr/bin/env bash
#
# Builds the installable plugin zip.
#
# Packages from `git archive` rather than the working tree, so the zip can only
# ever contain committed source. A zip built from uncommitted edits -- or a
# deployment patched out of band -- means the artifact under test is not the
# artifact in the repository, and any test result is worthless.
#
# Usage:
#   tools/build.sh            build from HEAD
#   tools/build.sh <ref>      build from a tag, branch, or commit

set -euo pipefail

REF="${1:-HEAD}"
SLUG="controlled-atmosphere"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

cd "$ROOT"

if ! git diff --quiet HEAD -- . ':!*.zip' 2>/dev/null; then
	echo "WARNING: working tree has uncommitted changes." >&2
	echo "         The zip is built from $REF and will NOT include them." >&2
	echo >&2
fi

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

# export-ignore in .gitattributes keeps docs and dotfiles out of the archive.
mkdir -p "$STAGE/$SLUG"
git archive "$REF" | tar -x -C "$STAGE/$SLUG"

# Nothing outside the plugin itself belongs in an installable package.
rm -rf "$STAGE/$SLUG/tools" "$STAGE/$SLUG/docs"

OUT="$ROOT/$SLUG.zip"
rm -f "$OUT"

if command -v zip >/dev/null 2>&1; then
	( cd "$STAGE" && zip -qr "$OUT" "$SLUG" )
else
	# Windows without zip(1); PowerShell ships a compressor.
	powershell -NoProfile -Command \
		"Compress-Archive -Path '$(cygpath -w "$STAGE/$SLUG")' -DestinationPath '$(cygpath -w "$OUT")' -Force"
fi

echo "built $SLUG.zip from $REF ($(git rev-parse --short "$REF"))"
echo
unzip -l "$OUT" 2>/dev/null | tail -n +4 | head -n -2 || true
