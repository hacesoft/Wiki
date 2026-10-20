#!/bin/sh
# Full-project release helper; preserves project files for GitHub/NAS and creates isolated runtime artifacts.
# This script never signs or publishes; signing and App Store publication belong to Hacesoft Publisher.
set -eu
ROOT="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
SRC="$ROOT/src"
INFO="$SRC/appinfo/info.xml"
[ -f "$INFO" ] || { echo "ERROR: missing src/appinfo/info.xml" >&2; exit 1; }
APP_ID="$(sed -n 's:.*<id>\([^<]*\)</id>.*:\1:p' "$INFO" | head -n 1)"
VERSION="$(sed -n 's:.*<version>\([^<]*\)</version>.*:\1:p' "$INFO" | head -n 1)"
case "$APP_ID" in ''|*[!a-z0-9_]*) echo 'ERROR: unsafe app ID' >&2; exit 1;; esac
case "$VERSION" in ''|*[!0-9A-Za-z.+_-]*) echo 'ERROR: invalid version' >&2; exit 1;; esac
for cmd in zip unzip tar sha256sum; do command -v "$cmd" >/dev/null 2>&1 || { echo "ERROR: missing command $cmd" >&2; exit 1; }; done
for f in LICENSE README.md README_CZ.md CHANGELOG.md install.sh uninstall.sh; do [ -f "$ROOT/$f" ] || { echo "ERROR: missing $f" >&2; exit 1; }; done
for f in appinfo/info.xml img/app.svg; do [ -f "$SRC/$f" ] || { echo "ERROR: missing src/$f" >&2; exit 1; }; done
for f in scripts/install-transaction.sh scripts/custom-apps-safety.sh; do [ -f "$ROOT/$f" ] || { echo "ERROR: missing installer helper $f" >&2; exit 1; }; done
# Disallow local accidental publishing/replacing previously built output.
RELEASE="$ROOT/release"
SOURCE_NAME="$APP_ID-$VERSION-full-source.zip"
for f in "$APP_ID-$VERSION.tar.gz" "$APP_ID-$VERSION.zip" "$SOURCE_NAME"; do
  [ ! -e "$RELEASE/$f" ] || { echo "ERROR: release/$f already exists; refusing overwrite" >&2; exit 1; }
done
if [ -f "$ROOT/scripts/check-languages.py" ]; then python3 "$ROOT/scripts/check-languages.py"; fi
TMP="$(mktemp -d "${TMPDIR:-/tmp}/$APP_ID-build.XXXXXX")"
trap 'rm -rf "$TMP"' EXIT HUP INT TERM
APP="$TMP/$APP_ID"; mkdir -p "$APP"
for item in appinfo bin css img js l10n lib templates; do
  [ ! -d "$SRC/$item" ] || cp -R "$SRC/$item" "$APP/$item"
done
for file in LICENSE CHANGELOG.md CHANGELOG.cs.md; do [ ! -f "$ROOT/$file" ] || cp "$ROOT/$file" "$APP/$file"; done
# Full source contains both source and deployment scripts and required helper files.
SOURCE="$TMP/$APP_ID-$VERSION"; mkdir -p "$SOURCE"
for item in .gitignore .gitattributes .github README.md README_CZ.md CHANGELOG.md CHANGELOG.cs.md CHANGELOG.en.md LICENSE docs assets scripts tests build src install.sh uninstall.sh build-release.sh; do
  [ ! -e "$ROOT/$item" ] || cp -R "$ROOT/$item" "$SOURCE/$item"
done
for file in install.sh uninstall.sh scripts/install-transaction.sh scripts/custom-apps-safety.sh src/appinfo/info.xml; do
  [ -f "$SOURCE/$file" ] || { echo "ERROR: incomplete full-source stage ($file)" >&2; exit 1; }
done
(cd "$TMP" && tar -czf "$TMP/$APP_ID-$VERSION.tar.gz" "$APP_ID" && zip -qr "$TMP/$APP_ID-$VERSION.zip" "$APP_ID" && zip -qr "$TMP/$SOURCE_NAME" "$APP_ID-$VERSION")
unzip -tqq "$TMP/$APP_ID-$VERSION.zip"
unzip -tqq "$TMP/$SOURCE_NAME"
mkdir -p "$RELEASE"
for f in "$APP_ID-$VERSION.tar.gz" "$APP_ID-$VERSION.zip" "$SOURCE_NAME"; do
  [ ! -e "$RELEASE/$f" ] || { echo "ERROR: conflict during build: $f" >&2; exit 1; }
done
for f in "$APP_ID-$VERSION.tar.gz" "$APP_ID-$VERSION.zip" "$SOURCE_NAME"; do mv "$TMP/$f" "$RELEASE/$f"; done
(cd "$RELEASE" && sha256sum "$APP_ID-$VERSION.tar.gz" "$APP_ID-$VERSION.zip" "$SOURCE_NAME" > "SHA256SUMS-$VERSION.txt")
printf 'Built release/%s\n' "$APP_ID-$VERSION.tar.gz" "$APP_ID-$VERSION.zip" "$SOURCE_NAME"
