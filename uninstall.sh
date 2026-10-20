#!/bin/sh
set -eu
PROJECT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
INFO="$PROJECT_DIR/src/appinfo/info.xml"
APP_ID="hc_homewiki"
APP_NAME="Home Wiki"
WEB_CONTAINER=""; CRON_CONTAINER=""
# say: Vstup: text v $*. Výstup: jeden řádek na stdout.
say(){ printf '%s\n' "$*"; }
# die: Vstup: text chyby v $*. Výstup: stderr a ukončení s kódem 1.
die(){ printf 'ERROR: %s\n' "$*" >&2; exit 1; }
[ "$(id -u)" -eq 0 ] || die "Run this uninstaller with: sudo sh uninstall.sh"
command -v docker >/dev/null 2>&1 || die "Docker was not found."
if [ -f "$INFO" ]; then
  v="$(sed -n 's:.*<id>\([^<]*\)</id>.*:\1:p' "$INFO" | head -n1)"; [ -n "$v" ] && APP_ID="$v"
fi
# container_has_occ: Vstup: název kontejneru. Výstup: návratový kód 0, pokud je uvnitř Nextcloud occ.
container_has_occ(){ docker exec "$1" sh -c 'test -f /var/www/html/occ' >/dev/null 2>&1; }
if docker ps --format '{{.Names}}' | grep -qx nextcloud-app && container_has_occ nextcloud-app; then WEB_CONTAINER=nextcloud-app; fi
if docker ps --format '{{.Names}}' | grep -qx nextcloud-cron && container_has_occ nextcloud-cron; then CRON_CONTAINER=nextcloud-cron; fi
if [ -z "$WEB_CONTAINER" ]; then for c in $(docker ps --format '{{.Names}}' | grep -Ei nextcloud || true); do case "$c" in *cron*) continue;; esac; if container_has_occ "$c"; then WEB_CONTAINER="$c"; break; fi; done; fi
[ -n "$WEB_CONTAINER" ] || die "Could not find the Nextcloud web container."
TARGET="/var/www/html/custom_apps/$APP_ID"
say "$APP_NAME uninstaller"
say "IMPORTANT: this removes only application code. Shared App Core is never removed."
say "User HomeWiki/ files and hc_homewiki_* database tables are preserved."
if docker exec -u www-data "$WEB_CONTAINER" php /var/www/html/occ app:list --enabled 2>/dev/null | grep -q -- "- $APP_ID:"; then
  docker exec -u www-data "$WEB_CONTAINER" php /var/www/html/occ app:disable "$APP_ID"
fi
docker exec "$WEB_CONTAINER" rm -rf "$TARGET" "$TARGET.new" >/dev/null 2>&1 || true
if [ -n "$CRON_CONTAINER" ] && [ "$CRON_CONTAINER" != "$WEB_CONTAINER" ]; then docker exec "$CRON_CONTAINER" rm -rf "$TARGET" "$TARGET.new" >/dev/null 2>&1 || true; fi
docker exec "$WEB_CONTAINER" php -r 'if (function_exists("opcache_reset")) { opcache_reset(); }' >/dev/null 2>&1 || true
docker exec "$WEB_CONTAINER" sh -c "test ! -e '$TARGET'" || die "Application directory still exists: $TARGET"
say "$APP_NAME application code removed successfully."
