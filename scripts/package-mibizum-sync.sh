#!/usr/bin/env bash
#
# package-mibizum-sync.sh
#
# Packages the Magento 1 module **Mibizum_Sync** for distribution through ALL
# the standard Magento 1.x channels. Generates, in dist/:
#
#   1. Mibizum_Sync-<ver>.tgz                 -> direct install (extract at the
#                                               Magento root). app/ + skin/.
#   2. Mibizum_Sync-<ver>-ftp-files.tgz       -> FTP phase 1: EVERYTHING except
#                                               the activator app/etc/modules/*.xml.
#   3. Mibizum_Sync-<ver>-ftp-activator.tgz   -> FTP phase 2: ONLY the activator.
#   4. Mibizum_Sync-<ver>-connect.tgz         -> Magento Connect package
#                                               (package.xml + tree) for the
#                                               Connect Manager / `./mage install`.
#
# Each artifact carries its own .sha256.
#
# -- Why the FTP install is done in TWO PHASES ---------------------------------
# Magento fires the setup/upgrade SQL scripts the first time it loads the config
# of a module whose activator (app/etc/modules/Mibizum_Sync.xml) is present and
# <active>true</active>. If you upload the files over FTP and the activator
# arrives BEFORE the classes / SQL scripts finish uploading, Magento may start
# the setup with half-written files -> "class not found" or SQL errors that
# leave the store in a bad state. The classic, safe solution:
#   PHASE 1: upload the COMPLETE ftp-files.tgz (no activator). Magento still does
#            not see the module, so nothing runs.
#   PHASE 2: once phase 1 is fully done, upload ftp-activator.tgz (only the
#            .xml). Now, with ALL classes already on disk, Magento runs a clean
#            setup.
# (Direct extraction, composer or Connect installs do NOT need this: they are
#  atomic (all files land before Magento looks).)
#
# Only packages Mibizum_Sync (a generic, standalone module).
#
# Usage:
#   bash scripts/package-mibizum-sync.sh
#
set -euo pipefail

# macOS: stop `tar` from embedding AppleDouble (._*) companions for extended
# attributes. Without this, the tarball gets entries like
# `app/etc/modules/._Mibizum_Sync.xml`; once extracted on a Linux server Magento
# loads EVERY *.xml in app/etc/modules and would choke on that binary blob. The
# `--exclude='._*'` below only filters on-disk files, not these tar-generated
# companions, so the env var is the actual fix.
export COPYFILE_DISABLE=1

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/.." && pwd)"        # repo root
SRC="$ROOT/src"
DIST="$ROOT/dist"
CONFIG="$SRC/app/code/community/Mibizum/Sync/etc/config.xml"

if [ ! -f "$CONFIG" ]; then
  echo "ERROR: cannot find the module config.xml at $CONFIG" >&2
  exit 1
fi

# Module version (from the <Mibizum_Sync><version>... block).
VERSION="$(grep -oE '<version>[^<]+</version>' "$CONFIG" | head -1 | sed -E 's#</?version>##g')"
[ -n "$VERSION" ] || { echo "ERROR: could not read the version from config.xml" >&2; exit 1; }

mkdir -p "$DIST"

# The activator, kept apart from the rest.
ACTIVATOR="app/etc/modules/Mibizum_Sync.xml"

# Paths that make up the Mibizum_Sync module (relative to src/) WITHOUT the activator.
FILES_PATHS=(
  "app/code/community/Mibizum/Sync"
  "app/design/frontend/base/default/layout/mibizum_sync.xml"
  "app/design/frontend/base/default/template/mibizum_sync"
  "app/design/adminhtml/default/default/layout/mibizum_sync.xml"
  "app/design/adminhtml/default/default/template/mibizum_sync"
  "skin/adminhtml/default/default/mibizum_sync"
)

# Locale CSVs: one Mibizum_Sync.csv per language (es_ES, de_DE, fr_FR, ...).
# Added at FILE granularity (never the whole app/locale/ dir) so we never
# overshadow other modules' translations. New languages are picked up here
# automatically.
while IFS= read -r _loc; do
  FILES_PATHS+=("$_loc")
done < <(cd "$SRC" && ls -1 app/locale/*/Mibizum_Sync.csv 2>/dev/null)

# Full set = activator + files.
ALL_PATHS=("$ACTIVATOR" "${FILES_PATHS[@]}")

# Verify all of them exist before packaging (fail loudly if something was renamed).
cd "$SRC"
for p in "${ALL_PATHS[@]}"; do
  [ -e "$p" ] || { echo "ERROR: missing '$p' in $SRC (renamed/moved?)" >&2; exit 1; }
done

TAR_EXCLUDES=(--exclude='.DS_Store' --exclude='._*' --exclude='*.swp')

checksum() {
  # $1 = absolute path of the file to hash -> writes <file>.sha256 next to it.
  ( cd "$(dirname "$1")" && shasum -a 256 "$(basename "$1")" > "$(basename "$1").sha256" )
}

echo "==> Packaging Mibizum_Sync v$VERSION..."

# -- 1. FULL artifact (direct install) -----------------------------------------
FULL="$DIST/Mibizum_Sync-$VERSION.tgz"
tar -czf "$FULL" "${TAR_EXCLUDES[@]}" "${ALL_PATHS[@]}"
checksum "$FULL"

# -- 2. FTP phase 1 artifact (everything except the activator) ------------------
FTP_FILES="$DIST/Mibizum_Sync-$VERSION-ftp-files.tgz"
tar -czf "$FTP_FILES" "${TAR_EXCLUDES[@]}" "${FILES_PATHS[@]}"
checksum "$FTP_FILES"

# -- 3. FTP phase 2 artifact (only the activator) ------------------------------
FTP_ACTIVATOR="$DIST/Mibizum_Sync-$VERSION-ftp-activator.tgz"
tar -czf "$FTP_ACTIVATOR" "${TAR_EXCLUDES[@]}" "$ACTIVATOR"
checksum "$FTP_ACTIVATOR"

# -- 4. Magento Connect artifact (package.xml + tree) --------------------------
CONNECT="$DIST/Mibizum_Sync-$VERSION-connect.tgz"
if command -v php >/dev/null 2>&1; then
  STAGE="$(mktemp -d)"
  trap 'rm -rf "$STAGE"' EXIT
  php "$HERE/build-connect-package.php" "$STAGE/package.xml" >/dev/null
  # package.xml at the archive root + the real tree (includes the activator:
  # Connect extracts everything and then runs setup atomically).
  tar -czf "$CONNECT" "${TAR_EXCLUDES[@]}" -C "$STAGE" package.xml -C "$SRC" "${ALL_PATHS[@]}"
  checksum "$CONNECT"
  rm -rf "$STAGE"; trap - EXIT
else
  echo "    (PHP not available: skipping the Magento Connect package)" >&2
  CONNECT=""
fi

echo "==> Done. Artifacts in $DIST:"
for f in "$FULL" "$FTP_FILES" "$FTP_ACTIVATOR" "$CONNECT"; do
  [ -n "$f" ] || continue
  printf '    %s\n' "$(basename "$f")"
done

cat <<EOF

Install channels:

  - Direct:   tar -xzf $(basename "$FULL") -C /path/to/magento/
              rm -rf /path/to/magento/var/cache/*

  - FTP (2 phases, safe):
      1) upload $(basename "$FTP_FILES") and extract at the Magento root
      2) ONLY when (1) is done, upload $(basename "$FTP_ACTIVATOR") and extract it
      3) flush the cache (Admin > Cache Management > Flush)

  - Magento Connect:
      Admin > System > Magento Connect > Magento Connect Manager >
      "Direct package file upload" > upload $(basename "${CONNECT:-N/A}")
      (or ./mage install file://path/$(basename "${CONNECT:-N/A}"))

  - Composer:   composer require mibizum/sync-magento1
  - modman:     modman link /path/to/magento-openMage
EOF
