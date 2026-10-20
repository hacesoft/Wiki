#!/bin/sh
# Načíst z install.sh před prvním voláním occ. V custom_apps smí být jen živá
# aplikace pod přesným App ID; ostatní kopie patří do záloh mimo tento adresář.
hc_clean_custom_apps() {
    hc_app_id="$1"
    shift
    for hc_container in "$@"; do
        [ -n "$hc_container" ] || continue
        docker exec "$hc_container" sh -c '
            set -eu
            root=/var/www/html/custom_apps
            app_id=$1
            [ -d "$root" ] || exit 0
            data_dir=$(php -r '\''$CONFIG=[]; require "/var/www/html/config/config.php"; echo $CONFIG["datadirectory"] ?? "";'\'')
            case "$data_dir" in
                /*) ;;
                *) echo "ERROR: Cannot find the Nextcloud data directory for application backup." >&2; exit 1 ;;
            esac
            case "$data_dir" in "$root"|"$root"/*)
                echo "ERROR: Deployment backups may not reside under custom_apps." >&2; exit 1 ;;
            esac
            quarantine="$data_dir/hc-core-deployment-backups/custom-apps-quarantine"
            for candidate in "$root"/*; do
                [ -f "$candidate/appinfo/info.xml" ] || continue
                name=${candidate##*/}
                declared=$(sed -n "s:.*<id>\\([^<]*\\)</id>.*:\\1:p" "$candidate/appinfo/info.xml" | head -n 1)
                [ -n "$declared" ] || continue
                [ "$name" != "$declared" ] || continue
                # Only handle misnamed copies of the application being installed.
                # Other Nextcloud applications are never touched by this installer.
                [ "$declared" = "$app_id" ] || continue
                mkdir -p "$quarantine"
                destination="$quarantine/$declared.$(date +%Y%m%d%H%M%S).$$.$name"
                counter=0
                while [ -e "$destination" ]; do
                    counter=$((counter + 1))
                    destination="$quarantine/$declared.$(date +%Y%m%d%H%M%S).$$.$counter.$name"
                done
                mv "$candidate" "$destination" || exit 1
                echo "WARNING: Moved duplicate $candidate outside custom_apps to $destination"
            done
        ' sh "$hc_app_id" || return 1
    done
}

# Volá se z EXIT trap instalátoru. Uklízí jen vlastní cesty v /tmp;
# zálohy starého kódu mimo custom_apps zůstávají.
hc_cleanup_install_stage() {
    if [ -n "${WEB_CONTAINER:-}" ]; then
        for hc_temp in "${REMOTE_TMP:-}" "${NEW_DIR:-}" "${LINT_STAGE:-}" "${REMOTE_TEST:-}"; do
            case "$hc_temp" in
                /tmp/*) docker exec "$WEB_CONTAINER" rm -rf -- "$hc_temp" >/dev/null 2>&1 || true ;;
            esac
        done
    fi
    if [ -n "${CRON_CONTAINER:-}" ] && [ "$CRON_CONTAINER" != "${WEB_CONTAINER:-}" ]; then
        for hc_temp in "${CRON_TMP:-}" "${CRON_NEW:-}"; do
            case "$hc_temp" in
                /tmp/*) docker exec "$CRON_CONTAINER" rm -rf -- "$hc_temp" >/dev/null 2>&1 || true ;;
            esac
        done
    fi
    return 0
}
