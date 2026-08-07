#!/bin/sh
#
# Build the distributable plugin zip.
#
#   sh tools/build-zip.sh [ref]
#
# Writes ../rapls-pdf-image-creator.zip — next to the plugin directory, so it
# can be dropped straight into WordPress's plugin uploader.
#
# The archive comes from git, not the working tree, so uncommitted edits are
# never shipped by accident. Everything marked export-ignore in .gitattributes
# is left out: tests/, tools/, docs/, CLAUDE.md, dotfiles, and assets/ — the
# banners and screenshots there belong to the wordpress.org listing, not the
# download, and they account for essentially all of the zip's weight.

set -eu

REF="${1:-HEAD}"
SLUG="rapls-pdf-image-creator"

cd "$(dirname "$0")/.."
ROOT="$(pwd)"
OUT="$(dirname "$ROOT")/$SLUG.zip"

# The header and readme.txt must agree, or WordPress.org rejects the upload.
HEADER_VERSION="$(git show "$REF:$SLUG.php" | sed -n 's/^ \* Version:[[:space:]]*//p' | tr -d '[:space:]')"
CONST_VERSION="$(git show "$REF:$SLUG.php" | sed -n "s/^define('RAPLS_PIC_VERSION', '\(.*\)');/\1/p" | tr -d '[:space:]')"
STABLE_TAG="$(git show "$REF:readme.txt" | sed -n 's/^Stable tag:[[:space:]]*//p' | tr -d '[:space:]')"

if [ "$HEADER_VERSION" != "$CONST_VERSION" ] || [ "$HEADER_VERSION" != "$STABLE_TAG" ]; then
    echo "version mismatch — refusing to build:" >&2
    echo "  plugin header      $HEADER_VERSION" >&2
    echo "  RAPLS_PIC_VERSION  $CONST_VERSION" >&2
    echo "  readme Stable tag  $STABLE_TAG" >&2
    exit 1
fi

rm -f "$OUT"
git archive --format=zip --prefix="$SLUG/" -o "$OUT" "$REF"

echo "built $OUT"
echo "  version  $HEADER_VERSION"
echo "  ref      $REF ($(git rev-parse --short "$REF"))"
echo "  files    $(unzip -l "$OUT" | tail -1 | awk '{print $2}')"
echo "  size     $(du -h "$OUT" | cut -f1)"

# Anything listed here would have been a packaging mistake.
LEAKED="$(unzip -Z1 "$OUT" | grep -E '(^|/)(tests|tools|docs?|assets|node_modules)/|CLAUDE\.md|\.gitattributes|\.gitignore|\.DS_Store' || true)"
if [ -n "$LEAKED" ]; then
    echo "WARNING — files that must not ship leaked into the archive:" >&2
    echo "$LEAKED" >&2
    exit 1
fi

# Nothing but code, translations and the readme should be in here.
IMAGES="$(unzip -Z1 "$OUT" | grep -iE '\.(png|jpe?g|gif|webp|svg|bmp|tiff?)$' || true)"
if [ -n "$IMAGES" ]; then
    echo "WARNING — images found in the archive:" >&2
    echo "$IMAGES" >&2
    exit 1
fi

echo "  excluded tests/, tools/, docs/, assets/, CLAUDE.md, dotfiles — verified"
echo "  no image files in the archive — verified"
