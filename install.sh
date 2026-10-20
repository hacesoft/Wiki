#!/bin/sh
set -eu

# Úspěšná instalace vypíše stejné čtyři řádky ve všech aplikacích.
# Při chybě se místo toho zobrazí celý protokol.
if [ "${HC_INSTALL_VERBOSE_INTERNAL:-0}" != "1" ]; then
    INSTALL_LOG="$(mktemp)"
    trap 'rm -f "$INSTALL_LOG"' EXIT
    trap 'exit 129' HUP
    trap 'exit 130' INT
    trap 'exit 143' TERM
    if HC_INSTALL_VERBOSE_INTERNAL=1 sh "$0" >"$INSTALL_LOG" 2>&1; then
        APP_INFO="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)/src/appinfo/info.xml"
        APP_ID="$(sed -n 's:.*<id>\([^<]*\)</id>.*:\1:p' "$APP_INFO" | head -n 1)"
        APP_NAME="$(sed -n 's:.*<name>\([^<]*\)</name>.*:\1:p' "$APP_INFO" | head -n 1 | sed 's/&amp;/\&/g')"
        NEW_VERSION="$(sed -n 's:.*<version>\([^<]*\)</version>.*:\1:p' "$APP_INFO" | head -n 1)"
        OLD_VERSION="$(sed -n 's/^Install previous version: //p' "$INSTALL_LOG" | head -n 1)"
        WEB_NAME="$(sed -n 's/^Nextcloud web container: //p' "$INSTALL_LOG" | head -n 1)"
        if [ -z "$APP_ID" ] || [ -z "$NEW_VERSION" ] || [ -z "$OLD_VERSION" ] || [ -z "$WEB_NAME" ]; then
            cat "$INSTALL_LOG" >&2
            echo 'ERROR: Could not verify the installation summary.' >&2
            exit 1
        fi
        SIZE_KIB="$(docker exec "$WEB_NAME" du -sk "/var/www/html/custom_apps/$APP_ID" 2>/dev/null | awk 'NR==1 {print $1}')"
        case "$SIZE_KIB" in ''|*[!0-9]*) SIZE_KIB="$(du -sk "$(dirname "$APP_INFO")/.." | awk 'NR==1 {print $1}')";; esac
        printf 'Aplikace: %s\nVerze: %s -> %s\nVelikost instalace: %s KiB\nHotovo.\n' "${APP_NAME:-$APP_ID}" "$OLD_VERSION" "$NEW_VERSION" "$SIZE_KIB"
        grep '^WARNING:' "$INSTALL_LOG" >&2 || true
        exit 0
    else
        STATUS=$?
        cat "$INSTALL_LOG" >&2
        exit "$STATUS"
    fi
fi


PROJECT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
APP_DIR="$PROJECT_DIR/src"
INFO="$APP_DIR/appinfo/info.xml"
CORE_MANIFEST="$APP_DIR/appinfo/hc_shared_app_core.json"
APP_ID="hc_homewiki"
CORE_ID="hc_shared_app_core"
RUNTIME_ITEMS="appinfo css img js l10n lib templates"
LOCK_DIR="/tmp/${APP_ID}.install.lock"
WEB_CONTAINER=""
CRON_CONTAINER=""
BACKUP_DIR=""
. "$PROJECT_DIR/scripts/install-transaction.sh"

# say: Vstup: text v $*. Výstup: jeden řádek na standardní výstup.
say() { printf '%s\n' "$*"; }
# die: Vstup: text chyby v $*. Výstup: chybová zpráva na stderr a ukončení s kódem 1.
die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
# cleanup: Vstup: stav ukončované instalace. Výstup: rollback při chybě a úklid dočasných prostředků.
cleanup() {
 status=$?
 trap - EXIT HUP INT TERM
 if [ "$status" -ne 0 ]; then
  hc_tx_restore || true
 fi
 if command -v hc_cleanup_install_stage >/dev/null 2>&1; then hc_cleanup_install_stage; fi
 [ -z "${TMP_LOCAL:-}" ] || rm -rf "$TMP_LOCAL"
 rmdir "$LOCK_DIR" 2>/dev/null || true
 exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

[ "$(id -u)" -eq 0 ] || die "Run this installer with: sudo sh install.sh"
command -v docker >/dev/null 2>&1 || die "Docker was not found."
[ -f "$INFO" ] || die "Missing $INFO"
[ -f "$CORE_MANIFEST" ] || die "Missing $CORE_MANIFEST"
mkdir "$LOCK_DIR" 2>/dev/null || die "Another $APP_ID installation appears to be running: $LOCK_DIR"
chmod 755 "$0" 2>/dev/null || true

APP_NAME="$(sed -n 's:.*<name>\([^<]*\)</name>.*:\1:p' "$INFO" | head -n1)"
APP_VERSION="$(sed -n 's:.*<version>\([^<]*\)</version>.*:\1:p' "$INFO" | head -n1)"
MIN_NC="$(sed -n 's:.*<nextcloud[^>]*min-version="\([0-9][0-9]*\)".*:\1:p' "$INFO" | head -n1)"
MAX_NC="$(sed -n 's:.*<nextcloud[^>]*max-version="\([0-9][0-9]*\)".*:\1:p' "$INFO" | head -n1)"
REQUIRED_CORE_VERSION="$(sed -n 's/.*"requiredVersion"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "$CORE_MANIFEST" | head -n1)"
[ -n "$APP_VERSION" ] || die "Could not determine application version."
[ "$MIN_NC" = "35" ] && [ "$MAX_NC" = "35" ] || die "This release must target Nextcloud 35 only."
printf '%s' "$REQUIRED_CORE_VERSION" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+([+-].*)?$' || die "Invalid required Core SemVer in manifest."
for item in $RUNTIME_ITEMS; do [ -e "$APP_DIR/$item" ] || die "Missing runtime item: $item"; done

# container_has_occ: Vstup: název Docker kontejneru. Výstup: návratový kód 0, pokud obsahuje Nextcloud occ.
container_has_occ() { docker exec "$1" sh -c 'test -f /var/www/html/occ' >/dev/null 2>&1; }
if docker ps --format '{{.Names}}' | grep -qx 'nextcloud-app' && container_has_occ nextcloud-app; then WEB_CONTAINER=nextcloud-app; fi
if docker ps --format '{{.Names}}' | grep -qx 'nextcloud-cron' && container_has_occ nextcloud-cron; then CRON_CONTAINER=nextcloud-cron; fi
if [ -z "$WEB_CONTAINER" ]; then
    for c in $(docker ps --format '{{.Names}}' | grep -Ei 'nextcloud' || true); do
        case "$c" in *cron*) continue ;; esac
        if container_has_occ "$c"; then WEB_CONTAINER="$c"; break; fi
    done
fi
if [ -z "$CRON_CONTAINER" ]; then
    for c in $(docker ps --format '{{.Names}}' | grep -Ei 'nextcloud.*cron|cron.*nextcloud' || true); do
        if container_has_occ "$c"; then CRON_CONTAINER="$c"; break; fi
    done
fi
[ -n "$WEB_CONTAINER" ] || die "Could not find the Nextcloud web container."
say "Nextcloud web container: $WEB_CONTAINER"
[ -n "$CRON_CONTAINER" ] && say "Nextcloud cron container: $CRON_CONTAINER"

# Před prvním occ odsunout staré duplicitní kopie mimo custom_apps.
. "$PROJECT_DIR/scripts/custom-apps-safety.sh"
hc_clean_custom_apps "$APP_ID" "$WEB_CONTAINER" "$CRON_CONTAINER" || die "Could not safely move duplicate application trees out of custom_apps."

NC_STATUS="$(docker exec -u www-data "$WEB_CONTAINER" php /var/www/html/occ status --output=json 2>/dev/null || true)"
NC_VERSION="$(printf '%s' "$NC_STATUS" | sed -n 's/.*"versionstring":"\([^"]*\)".*/\1/p')"
[ -n "$NC_VERSION" ] || die "Could not determine Nextcloud version."
NC_MAJOR="$(printf '%s' "$NC_VERSION" | cut -d. -f1)"
[ "$NC_MAJOR" = "35" ] || die "$APP_NAME $APP_VERSION supports Nextcloud 35 only; detected: $NC_VERSION"
say "Nextcloud version: $NC_VERSION"
PREVIOUS_VERSION="$(docker exec -u www-data "$WEB_CONTAINER" php /var/www/html/occ config:app:get "$APP_ID" installed_version 2>/dev/null || true)"
say "Install previous version: ${PREVIOUS_VERSION:-nová instalace}"

# version_ge: Vstup: dvě SemVer verze. Výstup: návratový kód 0, pokud je první verze >= druhé.
version_ge() {
    docker exec "$WEB_CONTAINER" php -r 'exit(version_compare($argv[1], $argv[2], ">=") ? 0 : 1);' "$1" "$2"
}
CORE_INFO="/var/www/html/custom_apps/$CORE_ID/appinfo/info.xml"
CORE_FILE_VERSION="$(docker exec "$WEB_CONTAINER" sh -c "test -f '$CORE_INFO' && sed -n 's:.*<version>\([^<]*\)</version>.*:\1:p' '$CORE_INFO' | head -n1" 2>/dev/null || true)"
CORE_INSTALLED_VERSION="$(docker exec -u www-data "$WEB_CONTAINER" php /var/www/html/occ config:app:get "$CORE_ID" installed_version 2>/dev/null || true)"
[ -n "$CORE_FILE_VERSION" ] || die "Shared App Core runtime is missing. Install Core $REQUIRED_CORE_VERSION first."
[ -n "$CORE_INSTALLED_VERSION" ] || die "Shared App Core installed_version is missing. Repair or reinstall Core before installing Home Wiki."
[ "$CORE_FILE_VERSION" = "$CORE_INSTALLED_VERSION" ] || die "Shared App Core runtime/config version mismatch. info.xml=$CORE_FILE_VERSION, installed_version=$CORE_INSTALLED_VERSION"
version_ge "$CORE_INSTALLED_VERSION" "$REQUIRED_CORE_VERSION" || die "Shared App Core $REQUIRED_CORE_VERSION or newer is required; installed: $CORE_INSTALLED_VERSION"
if ! docker exec -u www-data "$WEB_CONTAINER" php /var/www/html/occ app:list --enabled 2>/dev/null | grep -q -- "- $CORE_ID:"; then
    say "Enabling Shared App Core $CORE_INSTALLED_VERSION ..."
    docker exec -u www-data "$WEB_CONTAINER" php /var/www/html/occ app:enable "$CORE_ID"
fi
say "Shared App Core: $CORE_INSTALLED_VERSION (requirement >= $REQUIRED_CORE_VERSION: OK)"

TARGET_DIR="/var/www/html/custom_apps/$APP_ID"
STAMP="$(date +%Y%m%d_%H%M%S)"
DATA_DIRECTORY="$(docker exec -u www-data "$WEB_CONTAINER" php /var/www/html/occ config:system:get datadirectory)"
case "$DATA_DIRECTORY" in /*) ;; *) die "Cannot locate Nextcloud data directory for deployment backup.";; esac
BACKUP_DIR="${DATA_DIRECTORY}/hc-core-deployment-backups/${APP_ID}.${STAMP}.$$"
PREVIOUS_ENABLED=0
if docker exec -u www-data "$WEB_CONTAINER" php /var/www/html/occ app:list --enabled 2>/dev/null | grep -q -- "- $APP_ID:"; then PREVIOUS_ENABLED=1; fi
hc_tx_begin "$WEB_CONTAINER" "$CRON_CONTAINER" "$APP_ID" "$TARGET_DIR" "$BACKUP_DIR" "$PREVIOUS_VERSION" "$PREVIOUS_ENABLED"

TMP_LOCAL="$(mktemp -d)"
STAGE="$TMP_LOCAL/$APP_ID"; mkdir -p "$STAGE"
for item in $RUNTIME_ITEMS; do cp -R "$APP_DIR/$item" "$STAGE/"; done
REMOTE_TMP="/tmp/${APP_ID}.install.$$"
NEW_DIR="/tmp/${APP_ID}.new.$$"
docker exec "$WEB_CONTAINER" sh -c 'mkdir -p /var/www/html/custom_apps'
docker exec "$WEB_CONTAINER" rm -rf "$REMOTE_TMP" "$NEW_DIR" >/dev/null 2>&1 || true
docker exec "$WEB_CONTAINER" mkdir -p "$REMOTE_TMP"
docker cp "$STAGE/." "$WEB_CONTAINER:$REMOTE_TMP/"
docker exec "$WEB_CONTAINER" sh -c "mv '$REMOTE_TMP' '$NEW_DIR' && chown -R www-data:www-data '$NEW_DIR'"
# PHP lint proběhne ještě před deaktivací aplikace.
docker exec "$WEB_CONTAINER" sh -c 'find "$1" -type f -name "*.php" -exec php -l {} \;' sh "$NEW_DIR" >"$TMP_LOCAL/php-lint.log" 2>&1 || { cat "$TMP_LOCAL/php-lint.log"; die "Staged PHP validation failed."; }
if grep -q 'Errors parsing' "$TMP_LOCAL/php-lint.log"; then cat "$TMP_LOCAL/php-lint.log"; die "Staged PHP validation failed."; fi

hc_tx_disable_web
hc_tx_backup_web
hc_tx_place_web "$NEW_DIR"

if [ -n "$CRON_CONTAINER" ] && [ "$CRON_CONTAINER" != "$WEB_CONTAINER" ]; then
    PROBE_FILE=".hc-wiki-deploy-probe-$$"
    docker exec "$WEB_CONTAINER" touch "$TARGET_DIR/$PROBE_FILE"
    if ! docker exec "$CRON_CONTAINER" test -f "$TARGET_DIR/$PROBE_FILE"; then
        CRON_TMP="/tmp/${APP_ID}.install.$$"
        docker exec "$CRON_CONTAINER" sh -c 'mkdir -p /var/www/html/custom_apps'
        docker exec "$CRON_CONTAINER" rm -rf "$CRON_TMP" >/dev/null 2>&1 || true
        docker exec "$CRON_CONTAINER" mkdir -p "$CRON_TMP"
        docker cp "$STAGE/." "$CRON_CONTAINER:$CRON_TMP/"
        hc_tx_backup_cron
        hc_tx_place_cron "$CRON_TMP"
    fi
    docker exec "$WEB_CONTAINER" rm -f "$TARGET_DIR/$PROBE_FILE"
fi

# CLI opcache_reset je jen doplňkový; webové PHP se obnoví šetrně níže.
docker exec "$WEB_CONTAINER" php -r 'if (function_exists("opcache_reset")) { opcache_reset(); }' >/dev/null 2>&1 || true
say "Checking application database structure..."
docker exec -i -u www-data "$WEB_CONTAINER" php -l < "$PROJECT_DIR/scripts/ensure-schema.php" >/dev/null || die "Invalid database check PHP syntax."
docker exec -i -u www-data "$WEB_CONTAINER" php < "$PROJECT_DIR/scripts/ensure-schema.php" || die "Could not complete the application database structure; original rows have not been deleted."
# app:enable v Nextcloudu 35 při zapnutí lokálně umístěné aplikace volá Installer::installApp(),
# který sám spustí všechny čekající databázové migrace. Příkazy migrations:migrate/status
# jsou v NC35 dostupné jen při debug=true, proto je installer nesmí volat přímo.
docker exec -u www-data "$WEB_CONTAINER" php /var/www/html/occ app:enable "$APP_ID" || die "Home Wiki enable/migration step failed."
say "Home Wiki database migrations: OK"

APP_INSTALLED_VERSION="$(docker exec -u www-data "$WEB_CONTAINER" php /var/www/html/occ config:app:get "$APP_ID" installed_version 2>/dev/null || true)"
[ "$APP_INSTALLED_VERSION" = "$APP_VERSION" ] || die "Nextcloud installed_version mismatch: expected $APP_VERSION, reported ${APP_INSTALLED_VERSION:-missing}"

# Po zápisu verze šetrně obnovit webové PHP a verze načítaných souborů.
if docker exec "$WEB_CONTAINER" sh -c 'command -v apache2ctl >/dev/null 2>&1'; then
    docker exec "$WEB_CONTAINER" apache2ctl -k graceful >/dev/null 2>&1 || true
    say "Web runtime refresh requested: Apache graceful reload"
elif docker exec "$WEB_CONTAINER" sh -c 'test -r /proc/1/comm && grep -qi php-fpm /proc/1/comm'; then
    docker exec "$WEB_CONTAINER" kill -USR2 1 >/dev/null 2>&1 || true
    say "Web runtime refresh requested: PHP-FPM reload"
fi
sleep 2

DEPLOYED_VERSION="$(docker exec "$WEB_CONTAINER" sh -c "sed -n 's:.*<version>\([^<]*\)</version>.*:\1:p' '$TARGET_DIR/appinfo/info.xml' | head -n1")"
[ "$DEPLOYED_VERSION" = "$APP_VERSION" ] || die "Version verification failed: expected $APP_VERSION, got $DEPLOYED_VERSION"
for item in $RUNTIME_ITEMS; do docker exec "$WEB_CONTAINER" sh -c "test -e '$TARGET_DIR/$item'" || die "Missing deployed runtime item: $item"; done

say "$APP_NAME $DEPLOYED_VERSION deployment verification: OK"
say "User files under HomeWiki/ were not deleted."
