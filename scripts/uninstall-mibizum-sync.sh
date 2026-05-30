#!/usr/bin/env bash
#
# uninstall-mibizum-sync.sh
#
# Uninstalls Mibizum_Sync from a Magento 1.x store WITHOUT leaving junk behind:
#   1. DISABLES the module (removes the activator app/etc/modules/Mibizum_Sync.xml)
#      BEFORE touching anything else, so Magento does not recreate tables/defaults.
#   2. Cleans the DB: DROP the module tables, delete config from
#      core_config_data (mibizum_sync%, mibizum_sync_badges%) and the
#      mibizum_sync_setup row in core_resource. Reads creds + table_prefix from
#      app/etc/local.xml automatically.
#   3. Deletes the module files from the Magento tree.
#   4. Flushes the cache (var/cache).
#
# Idempotent and safe: if something no longer exists, it does not fail. If it
# cannot find the `mysql` client, it prints the resolved SQL so you can run it
# by hand.
#
# Usage:
#   bash uninstall-mibizum-sync.sh /path/to/magento [options]
#
# Options:
#   --yes          Do not ask for confirmation (for automation).
#   --dry-run      Show what it would do, without changing anything.
#   --keep-files   Clean DB + disable, but do NOT delete the files.
#   --keep-db      Delete files + disable, but do NOT touch the DB.
#   --disable-only Only remove the activator (safe, reversible pause).
#
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SQL_TEMPLATE="$HERE/uninstall-mibizum-sync.sql"

# -- Argument parsing ----------------------------------------------------------
MAGENTO_ROOT=""
ASSUME_YES=0
DRY_RUN=0
KEEP_FILES=0
KEEP_DB=0
DISABLE_ONLY=0

for arg in "$@"; do
  case "$arg" in
    --yes)          ASSUME_YES=1 ;;
    --dry-run)      DRY_RUN=1 ;;
    --keep-files)   KEEP_FILES=1 ;;
    --keep-db)      KEEP_DB=1 ;;
    --disable-only) DISABLE_ONLY=1 ;;
    -h|--help)
      sed -n '2,40p' "$0" | sed 's/^# \{0,1\}//'
      exit 0 ;;
    -*)
      echo "ERROR: unknown option '$arg'" >&2; exit 1 ;;
    *)
      if [ -z "$MAGENTO_ROOT" ]; then MAGENTO_ROOT="$arg"; else
        echo "ERROR: unexpected argument '$arg'" >&2; exit 1
      fi ;;
  esac
done

if [ -z "$MAGENTO_ROOT" ]; then
  echo "ERROR: provide the Magento root. Usage: $0 /path/to/magento [options]" >&2
  exit 1
fi
MAGENTO_ROOT="${MAGENTO_ROOT%/}"

LOCAL_XML="$MAGENTO_ROOT/app/etc/local.xml"
ACTIVATOR="$MAGENTO_ROOT/app/etc/modules/Mibizum_Sync.xml"

if [ ! -f "$MAGENTO_ROOT/app/Mage.php" ] && [ ! -f "$LOCAL_XML" ]; then
  echo "ERROR: '$MAGENTO_ROOT' does not look like a Magento root (no app/Mage.php nor app/etc/local.xml)" >&2
  exit 1
fi

# Module files to delete (relative to the Magento root).
# Note: the module code is removed from BOTH code pools. The distributable
# installs into community, but a legacy/manual deploy may have left a copy in
# local/, and Magento autoloads local/ BEFORE community/ - leaving the local
# copy would shadow the (removed) community one and break a later reinstall.
MODULE_PATHS=(
  "app/etc/modules/Mibizum_Sync.xml"
  "app/code/community/Mibizum/Sync"
  "app/code/local/Mibizum/Sync"
  "app/design/frontend/base/default/layout/mibizum_sync.xml"
  "app/design/frontend/base/default/template/mibizum_sync"
  "app/design/adminhtml/default/default/layout/mibizum_sync.xml"
  "app/design/adminhtml/default/default/template/mibizum_sync"
  "skin/adminhtml/default/default/mibizum_sync"
)

run() {
  # Run (or show, in dry-run) a command.
  if [ "$DRY_RUN" -eq 1 ]; then
    printf '  [dry-run] %s\n' "$*"
  else
    "$@"
  fi
}

# -- Read creds from local.xml with PHP (robust against special characters) ----
read_local_xml() {
  php -r '
    $f = $argv[1];
    $x = @simplexml_load_file($f);
    if ($x === false) { fwrite(STDERR, "could not parse local.xml\n"); exit(2); }
    $r = $x->global->resources;
    $c = $r->default_setup->connection;
    $vals = array(
      (string)$c->host,
      (string)$c->username,
      (string)$c->password,
      (string)$c->dbname,
      (string)$r->db->table_prefix,
    );
    echo implode("\0", $vals);
  ' "$1"
}

# -- Plan ----------------------------------------------------------------------
echo "=============================================================="
echo " Uninstall Mibizum_Sync from: $MAGENTO_ROOT"
echo "=============================================================="
if [ "$DISABLE_ONLY" -eq 1 ]; then
  echo " Mode: DISABLE ONLY (remove activator). Reversible."
else
  echo " This will:"
  echo "   - remove the activator (disable the module)"
  [ "$KEEP_DB" -eq 1 ]    || echo "   - clean the DB (tables + config + core_resource)"
  [ "$KEEP_FILES" -eq 1 ] || echo "   - delete the module files"
  echo "   - flush var/cache"
fi
[ "$DRY_RUN" -eq 1 ] && echo " (DRY-RUN: nothing will be changed)"
echo "--------------------------------------------------------------"

if [ "$ASSUME_YES" -ne 1 ] && [ "$DRY_RUN" -ne 1 ]; then
  printf "Continue? [y/N] "
  read -r ans
  case "$ans" in
    y|Y|yes|YES) ;;
    *) echo "Cancelled."; exit 0 ;;
  esac
fi

# -- 1. Disable (remove activator) ---------------------------------------------
echo "==> 1) Disabling module (remove activator)..."
if [ -f "$ACTIVATOR" ]; then
  run rm -f "$ACTIVATOR"
else
  echo "  (the activator was already gone)"
fi

if [ "$DISABLE_ONLY" -eq 1 ]; then
  echo "==> Done (disable only). Flush the cache if needed."
  [ "$DRY_RUN" -eq 1 ] || run rm -rf "$MAGENTO_ROOT"/var/cache/* 2>/dev/null || true
  exit 0
fi

# -- 2. Clean DB ---------------------------------------------------------------
if [ "$KEEP_DB" -ne 1 ]; then
  echo "==> 2) Cleaning the database..."
  if [ ! -f "$LOCAL_XML" ]; then
    echo "  WARNING: no local.xml; cannot read creds. Run the SQL by hand:" >&2
    echo "         $SQL_TEMPLATE  (replace @PREFIX@ with your table_prefix)" >&2
  else
    # Read creds (null-delimited).
    DB_HOST=""; DB_USER=""; DB_PASS=""; DB_NAME=""; DB_PREFIX=""
    {
      IFS= read -r -d '' DB_HOST || true
      IFS= read -r -d '' DB_USER || true
      IFS= read -r -d '' DB_PASS || true
      IFS= read -r -d '' DB_NAME || true
      IFS= read -r -d '' DB_PREFIX || true
    } < <(read_local_xml "$LOCAL_XML")

    [ -n "$DB_HOST" ] || DB_HOST="localhost"
    # local.xml sometimes carries host with :port or /socket. We pass it as-is to
    # --host; for rare cases (socket) the admin can run the SQL by hand.

    # SQL with the prefix substituted.
    SQL="$(sed "s/@PREFIX@/${DB_PREFIX//\//\\/}/g" "$SQL_TEMPLATE")"

    if command -v mysql >/dev/null 2>&1; then
      if [ "$DRY_RUN" -eq 1 ]; then
        echo "  [dry-run] mysql ($DB_NAME @ $DB_HOST, prefix='${DB_PREFIX}') <<SQL"
        echo "$SQL" | sed 's/^/    /'
      else
        # Creds via temp file (do not expose the password in ps).
        CNF="$(mktemp)"
        chmod 600 "$CNF"
        {
          echo "[client]"
          echo "host=$DB_HOST"
          echo "user=$DB_USER"
          echo "password=$DB_PASS"
        } > "$CNF"
        trap 'rm -f "$CNF"' EXIT
        printf '%s\n' "$SQL" | mysql --defaults-extra-file="$CNF" "$DB_NAME"
        rm -f "$CNF"; trap - EXIT
        echo "  DB cleaned (prefix='${DB_PREFIX}')."
      fi
    else
      echo "  WARNING: cannot find the 'mysql' client. Run this SQL by hand:" >&2
      echo "  --- 8< ---"
      echo "$SQL"
      echo "  --- >8 ---"
    fi
  fi
else
  echo "==> 2) (--keep-db) DB left intact."
fi

# -- 3. Delete files -----------------------------------------------------------
if [ "$KEEP_FILES" -ne 1 ]; then
  echo "==> 3) Deleting module files..."
  for p in "${MODULE_PATHS[@]}"; do
    target="$MAGENTO_ROOT/$p"
    if [ -e "$target" ]; then
      run rm -rf "$target"
    fi
  done
  # Locale CSVs: one Mibizum_Sync.csv per language. Removed at file granularity
  # so the store's other app/locale/<lang>/ translations are left untouched.
  for loc in "$MAGENTO_ROOT"/app/locale/*/Mibizum_Sync.csv; do
    [ -e "$loc" ] && run rm -f "$loc"
  done
else
  echo "==> 3) (--keep-files) Files left intact."
fi

# -- 4. Flush cache ------------------------------------------------------------
echo "==> 4) Flushing var/cache..."
[ "$DRY_RUN" -eq 1 ] || run rm -rf "$MAGENTO_ROOT"/var/cache/* 2>/dev/null || true

echo "==> Uninstall complete."
echo "    Verify in Admin > System > Configuration that 'Mibizum Sync' no longer appears."
