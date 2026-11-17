#!/bin/sh
set -eu

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
STEP=0
TOTAL_STEPS=12
STEP_OPEN=0
PREVIOUS_SIZE_KIB=""
TARGET_DIR="/var/www/html/custom_apps/$APP_ID"
. "$PROJECT_DIR/scripts/install-transaction.sh"

# step: Vstup: popis právě zahajovaného kroku. Výstup: okamžitě otevřený kompaktní řádek kroku.
step() {
    STEP=$((STEP + 1))
    STEP_OPEN=1
    printf '[%02d/%02d] %s' "$STEP" "$TOTAL_STEPS" "$*"
}
# ok: Vstup: žádný. Výstup: doplnění teček a OK na právě otevřený řádek.
ok() {
    printf ' ..... OK\n'
    STEP_OPEN=0
}
# say: Vstup: stručný detail v $*. Výstup: doplnění detailu na právě otevřený řádek.
say() { printf ' | %s' "$*"; }
# die: Vstup: text chyby v $*. Výstup: FAIL na řádku kroku, chyba na dalším řádku a ukončení s kódem 1.
die() {
    if [ "${STEP_OPEN:-0}" -eq 1 ]; then
        printf ' ..... FAIL\n' >&2
        STEP_OPEN=0
    fi
    printf 'ERROR: %s\n' "$*" >&2
    exit 1
}
# cleanup: Vstup: stav ukončované instalace. Výstup: rollback při chybě a úklid dočasných prostředků.
cleanup() {
    status=$?
    trap - EXIT HUP INT TERM
    if [ "$status" -ne 0 ]; then
        if [ "${STEP_OPEN:-0}" -eq 1 ]; then
            printf ' ..... FAIL\n' >&2
            STEP_OPEN=0
        fi
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

step "Kontroluji instalační balík a metadata aplikace..."
[ "$(id -u)" -eq 0 ] || die "Run this installer with: sudo sh install.sh"
command -v docker >/dev/null 2>&1 || die "Docker was not found."
[ -f "$INFO" ] || die "Missing $INFO"
[ -f "$CORE_MANIFEST" ] || die "Missing $CORE_MANIFEST"
mkdir "$LOCK_DIR" 2>/dev/null || die "Another $APP_ID installation appears to be running: $LOCK_DIR"
chmod 755 "$0" 2>/dev/null || true

APP_NAME="$(sed -n 's:.*<name>\([^<]*\)</name>.*:\1:p' "$INFO" | head -n1 | sed 's/&amp;/\&/g')"
APP_VERSION="$(sed -n 's:.*<version>\([^<]*\)</version>.*:\1:p' "$INFO" | head -n1)"
MIN_NC="$(sed -n 's:.*<nextcloud[^>]*min-version="\([0-9][0-9]*\)".*:\1:p' "$INFO" | head -n1)"
MAX_NC="$(sed -n 's:.*<nextcloud[^>]*max-version="\([0-9][0-9]*\)".*:\1:p' "$INFO" | head -n1)"
REQUIRED_CORE_VERSION="$(sed -n 's/.*"requiredVersion"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "$CORE_MANIFEST" | head -n1)"
[ -n "$APP_VERSION" ] || die "Could not determine application version."
[ "$MIN_NC" = "35" ] && [ "$MAX_NC" = "35" ] || die "This release must target Nextcloud 35 only."
printf '%s' "$REQUIRED_CORE_VERSION" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+([+-].*)?$' || die "Invalid required Core SemVer in manifest."
for item in $RUNTIME_ITEMS; do [ -e "$APP_DIR/$item" ] || die "Missing runtime item: $item"; done
say "${APP_NAME:-$APP_ID} $APP_VERSION"
ok

# container_has_occ: Vstup: název Docker kontejneru. Výstup: návratový kód 0, pokud obsahuje Nextcloud occ.
container_has_occ() { docker exec "$1" sh -c 'test -f /var/www/html/occ' >/dev/null 2>&1; }

step "Hledám Nextcloud kontejnery..."
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
if [ -n "$CRON_CONTAINER" ]; then say "web=$WEB_CONTAINER, cron=$CRON_CONTAINER"; else say "web=$WEB_CONTAINER, cron=nenalezen"; fi
ok

step "Kontroluji bezpečnost custom_apps a duplicitní kopie aplikace..."
# Před prvním occ odsunout staré duplicitní kopie mimo custom_apps.
. "$PROJECT_DIR/scripts/custom-apps-safety.sh"
SAFETY_OUTPUT="$(hc_clean_custom_apps "$APP_ID" "$WEB_CONTAINER" "$CRON_CONTAINER" 2>&1)" || die "Could not safely move duplicate application trees out of custom_apps. $SAFETY_OUTPUT"
if [ -n "$SAFETY_OUTPUT" ]; then
    say "duplicitní kopie přesunuta mimo custom_apps"
fi
ok

step "Kontroluji Nextcloud a stávající instalaci Home Wiki..."
NC_STATUS="$(docker exec -u www-data "$WEB_CONTAINER" php /var/www/html/occ status --output=json 2>/dev/null || true)"
NC_VERSION="$(printf '%s' "$NC_STATUS" | sed -n 's/.*"versionstring":"\([^"]*\)".*/\1/p')"
[ -n "$NC_VERSION" ] || die "Could not determine Nextcloud version."
NC_MAJOR="$(printf '%s' "$NC_VERSION" | cut -d. -f1)"
[ "$NC_MAJOR" = "35" ] || die "$APP_NAME $APP_VERSION supports Nextcloud 35 only; detected: $NC_VERSION"
PREVIOUS_VERSION="$(docker exec -u www-data "$WEB_CONTAINER" php /var/www/html/occ config:app:get "$APP_ID" installed_version 2>/dev/null || true)"
if docker exec "$WEB_CONTAINER" test -d "$TARGET_DIR" >/dev/null 2>&1; then
    PREVIOUS_SIZE_KIB="$(docker exec "$WEB_CONTAINER" du -sk "$TARGET_DIR" 2>/dev/null | awk 'NR==1 {print $1}')"
    case "$PREVIOUS_SIZE_KIB" in ''|*[!0-9]*) PREVIOUS_SIZE_KIB="";; esac
fi
if [ -n "$PREVIOUS_VERSION" ]; then
    if [ -n "$PREVIOUS_SIZE_KIB" ]; then
        say "Nextcloud $NC_VERSION; předchozí $PREVIOUS_VERSION ($PREVIOUS_SIZE_KIB KiB)"
    else
        say "Nextcloud $NC_VERSION; předchozí $PREVIOUS_VERSION"
    fi
else
    say "Nextcloud $NC_VERSION; nová instalace"
fi
ok

# version_ge: Vstup: dvě SemVer verze. Výstup: návratový kód 0, pokud je první verze >= druhé.
version_ge() {
    docker exec "$WEB_CONTAINER" php -r 'exit(version_compare($argv[1], $argv[2], ">=") ? 0 : 1);' "$1" "$2"
}

step "Kontroluji HC Shared App Core..."
CORE_INFO="/var/www/html/custom_apps/$CORE_ID/appinfo/info.xml"
CORE_FILE_VERSION="$(docker exec "$WEB_CONTAINER" sh -c "test -f '$CORE_INFO' && sed -n 's:.*<version>\([^<]*\)</version>.*:\1:p' '$CORE_INFO' | head -n1" 2>/dev/null || true)"
CORE_INSTALLED_VERSION="$(docker exec -u www-data "$WEB_CONTAINER" php /var/www/html/occ config:app:get "$CORE_ID" installed_version 2>/dev/null || true)"
[ -n "$CORE_FILE_VERSION" ] || die "Shared App Core runtime is missing. Install Core $REQUIRED_CORE_VERSION first."
[ -n "$CORE_INSTALLED_VERSION" ] || die "Shared App Core installed_version is missing. Repair or reinstall Core before installing Home Wiki."
[ "$CORE_FILE_VERSION" = "$CORE_INSTALLED_VERSION" ] || die "Shared App Core runtime/config version mismatch. info.xml=$CORE_FILE_VERSION, installed_version=$CORE_INSTALLED_VERSION"
version_ge "$CORE_INSTALLED_VERSION" "$REQUIRED_CORE_VERSION" || die "Shared App Core $REQUIRED_CORE_VERSION or newer is required; installed: $CORE_INSTALLED_VERSION"
if ! docker exec -u www-data "$WEB_CONTAINER" php /var/www/html/occ app:list --enabled 2>/dev/null | grep -q -- "- $CORE_ID:"; then
    CORE_ENABLE_OUTPUT="$(docker exec -u www-data "$WEB_CONTAINER" php /var/www/html/occ app:enable "$CORE_ID" 2>&1)" || die "Core activation failed: $CORE_ENABLE_OUTPUT"
fi
say "Core $CORE_INSTALLED_VERSION >= $REQUIRED_CORE_VERSION"
ok

step "Připravuji staging nové verze a transakční zálohu..."
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
docker cp "$STAGE/." "$WEB_CONTAINER:$REMOTE_TMP/" >/dev/null 2>&1 || die "Could not copy staged runtime into the web container."
docker exec "$WEB_CONTAINER" sh -c "mv '$REMOTE_TMP' '$NEW_DIR' && chown -R www-data:www-data '$NEW_DIR'"
say "staging připraven; záloha $BACKUP_DIR"
ok

step "Kontroluji PHP nové verze ještě před deaktivací aplikace..."
docker exec "$WEB_CONTAINER" sh -c 'find "$1" -type f -name "*.php" -exec php -l {} \;' sh "$NEW_DIR" >"$TMP_LOCAL/php-lint.log" 2>&1 || { cat "$TMP_LOCAL/php-lint.log"; die "Staged PHP validation failed."; }
if grep -q 'Errors parsing' "$TMP_LOCAL/php-lint.log"; then cat "$TMP_LOCAL/php-lint.log"; die "Staged PHP validation failed."; fi
ok

step "Deaktivuji Home Wiki a ukládám předchozí runtime..."
hc_tx_disable_web >/dev/null
hc_tx_backup_web >/dev/null
ok

step "Nasazuji nový runtime do webového a cron kontejneru..."
hc_tx_place_web "$NEW_DIR"
if [ -n "$CRON_CONTAINER" ] && [ "$CRON_CONTAINER" != "$WEB_CONTAINER" ]; then
    PROBE_FILE=".hc-wiki-deploy-probe-$$"
    docker exec "$WEB_CONTAINER" touch "$TARGET_DIR/$PROBE_FILE"
    if ! docker exec "$CRON_CONTAINER" test -f "$TARGET_DIR/$PROBE_FILE"; then
        CRON_TMP="/tmp/${APP_ID}.install.$$"
        docker exec "$CRON_CONTAINER" sh -c 'mkdir -p /var/www/html/custom_apps'
        docker exec "$CRON_CONTAINER" rm -rf "$CRON_TMP" >/dev/null 2>&1 || true
        docker exec "$CRON_CONTAINER" mkdir -p "$CRON_TMP"
        docker cp "$STAGE/." "$CRON_CONTAINER:$CRON_TMP/" >/dev/null 2>&1 || die "Could not copy staged runtime into the cron container."
        hc_tx_backup_cron >/dev/null
        hc_tx_place_cron "$CRON_TMP" >/dev/null
        say "web+cron mají oddělené custom_apps"
    else
        say "web+cron sdílejí custom_apps"
    fi
    docker exec "$WEB_CONTAINER" rm -f "$TARGET_DIR/$PROBE_FILE"
fi
ok

step "Kontroluji databázi, migrace a aktivuji Home Wiki..."
# CLI opcache_reset je jen doplňkový; webové PHP se obnoví šetrně níže.
docker exec "$WEB_CONTAINER" php -r 'if (function_exists("opcache_reset")) { opcache_reset(); }' >/dev/null 2>&1 || true
docker exec -i -u www-data "$WEB_CONTAINER" php -l < "$PROJECT_DIR/scripts/ensure-schema.php" >/dev/null || die "Invalid database check PHP syntax."
SCHEMA_OUTPUT="$(docker exec -i -u www-data "$WEB_CONTAINER" php < "$PROJECT_DIR/scripts/ensure-schema.php" 2>&1)" || die "Could not complete the application database structure; original rows have not been deleted. $SCHEMA_OUTPUT"
# app:enable v Nextcloudu 35 při zapnutí lokálně umístěné aplikace volá Installer::installApp(),
# který sám spustí všechny čekající databázové migrace. Příkazy migrations:migrate/status
# jsou v NC35 dostupné jen při debug=true, proto je installer nesmí volat přímo.
ENABLE_OUTPUT="$(docker exec -u www-data "$WEB_CONTAINER" php /var/www/html/occ app:enable "$APP_ID" 2>&1)" || die "Home Wiki enable/migration step failed. $ENABLE_OUTPUT"
APP_INSTALLED_VERSION="$(docker exec -u www-data "$WEB_CONTAINER" php /var/www/html/occ config:app:get "$APP_ID" installed_version 2>/dev/null || true)"
[ "$APP_INSTALLED_VERSION" = "$APP_VERSION" ] || die "Nextcloud installed_version mismatch: expected $APP_VERSION, reported ${APP_INSTALLED_VERSION:-missing}"
say "schema+migrace OK; installed_version=$APP_INSTALLED_VERSION"
ok

step "Obnovuji běh webového PHP po aktualizaci souborů..."
if docker exec "$WEB_CONTAINER" sh -c 'command -v apache2ctl >/dev/null 2>&1'; then
    docker exec "$WEB_CONTAINER" apache2ctl -k graceful >/dev/null 2>&1 || true
    say "Apache graceful reload"
elif docker exec "$WEB_CONTAINER" sh -c 'test -r /proc/1/comm && grep -qi php-fpm /proc/1/comm'; then
    docker exec "$WEB_CONTAINER" kill -USR2 1 >/dev/null 2>&1 || true
    say "PHP-FPM reload"
else
    say "reload nebyl potřeba/dostupný"
fi
sleep 2
ok

step "Provádím závěrečnou kontrolu nasazené verze..."
DEPLOYED_VERSION="$(docker exec "$WEB_CONTAINER" sh -c "sed -n 's:.*<version>\([^<]*\)</version>.*:\1:p' '$TARGET_DIR/appinfo/info.xml' | head -n1")"
[ "$DEPLOYED_VERSION" = "$APP_VERSION" ] || die "Version verification failed: expected $APP_VERSION, got $DEPLOYED_VERSION"
for item in $RUNTIME_ITEMS; do docker exec "$WEB_CONTAINER" sh -c "test -e '$TARGET_DIR/$item'" || die "Missing deployed runtime item: $item"; done
SIZE_KIB="$(docker exec "$WEB_CONTAINER" du -sk "$TARGET_DIR" 2>/dev/null | awk 'NR==1 {print $1}')"
case "$SIZE_KIB" in ''|*[!0-9]*) SIZE_KIB="?";; esac
say "$APP_NAME $DEPLOYED_VERSION; runtime $SIZE_KIB KiB; data HomeWiki/ zachována"
ok

printf '\n=== INSTALACE DOKONČENA ÚSPĚŠNĚ ===\n'
if [ -n "$PREVIOUS_VERSION" ]; then
    if [ -n "$PREVIOUS_SIZE_KIB" ]; then
        printf 'Home Wiki: %s (%s KiB) -> %s (%s KiB)\n' "$PREVIOUS_VERSION" "$PREVIOUS_SIZE_KIB" "$DEPLOYED_VERSION" "$SIZE_KIB"
    else
        printf 'Home Wiki: %s -> %s (%s KiB)\n' "$PREVIOUS_VERSION" "$DEPLOYED_VERSION" "$SIZE_KIB"
    fi
else
    printf 'Home Wiki: nová instalace -> %s (%s KiB)\n' "$DEPLOYED_VERSION" "$SIZE_KIB"
fi
printf 'Záloha předchozího runtime: %s\n' "$BACKUP_DIR"
