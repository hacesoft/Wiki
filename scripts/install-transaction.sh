#!/bin/sh
# Společná obnova instalace. Soubor se načítá přes ".", nespouští samostatně.
# Záloha leží v datovém adresáři Nextcloudu, nikdy v custom_apps.

hc_tx_begin() {
    HC_TX_WEB=$1 HC_TX_CRON=$2 HC_TX_ID=$3 HC_TX_TARGET=$4
    HC_TX_BACKUP=$5 HC_TX_OLD_VERSION=$6 HC_TX_OLD_ENABLED=$7
    HC_TX_WEB_BACKUP=0 HC_TX_WEB_NEW=0 HC_TX_CRON_BACKUP=0 HC_TX_CRON_NEW=0
    HC_TX_TOUCHED=0
}

hc_tx_disable_web() {
    if [ "$HC_TX_OLD_ENABLED" -eq 1 ]; then
        HC_TX_TOUCHED=1
        docker exec -u www-data "$HC_TX_WEB" php /var/www/html/occ app:disable "$HC_TX_ID"
    fi
}

hc_tx_backup_web() {
    if docker exec "$HC_TX_WEB" test -d "$HC_TX_TARGET"; then
        docker exec "$HC_TX_WEB" mkdir -p "$HC_TX_BACKUP"
        docker exec "$HC_TX_WEB" mv "$HC_TX_TARGET" "$HC_TX_BACKUP/runtime"
        HC_TX_WEB_BACKUP=1 HC_TX_TOUCHED=1
    fi
}

hc_tx_place_web() {
    docker exec "$HC_TX_WEB" mv "$1" "$HC_TX_TARGET"
    HC_TX_WEB_NEW=1 HC_TX_TOUCHED=1
    docker exec "$HC_TX_WEB" chown -R www-data:www-data "$HC_TX_TARGET"
}

hc_tx_backup_cron() {
    if docker exec "$HC_TX_CRON" test -d "$HC_TX_TARGET"; then
        docker exec "$HC_TX_CRON" mkdir -p "$HC_TX_BACKUP"
        docker exec "$HC_TX_CRON" mv "$HC_TX_TARGET" "$HC_TX_BACKUP/cron-runtime"
        HC_TX_CRON_BACKUP=1
    fi
}

hc_tx_place_cron() {
    docker exec "$HC_TX_CRON" mv "$1" "$HC_TX_TARGET"
    HC_TX_CRON_NEW=1
    docker exec "$HC_TX_CRON" chown -R www-data:www-data "$HC_TX_TARGET"
}

hc_tx_restore() {
    [ "${HC_TX_TOUCHED:-0}" -eq 1 ] || [ "${HC_TX_CRON_BACKUP:-0}" -eq 1 ] || [ "${HC_TX_CRON_NEW:-0}" -eq 1 ] || return 0
    printf 'Instalace selhala; obnovuji předchozí kód aplikace.\n' >&2
    hc_tx_failed=0

    # Cron může mít vlastní custom_apps. Obnoví se dříve než web a původní úloha.
    if [ "${HC_TX_CRON_NEW:-0}" -eq 1 ]; then
        docker exec "$HC_TX_CRON" rm -rf "$HC_TX_TARGET" || hc_tx_failed=1
    fi
    if [ "${HC_TX_CRON_BACKUP:-0}" -eq 1 ]; then
        docker exec "$HC_TX_CRON" cp -a "$HC_TX_BACKUP/cron-runtime" "$HC_TX_TARGET" || hc_tx_failed=1
    fi

    docker exec -u www-data "$HC_TX_WEB" php /var/www/html/occ app:disable "$HC_TX_ID" >/dev/null 2>&1 || true
    if [ "${HC_TX_WEB_NEW:-0}" -eq 1 ]; then
        docker exec "$HC_TX_WEB" rm -rf "$HC_TX_TARGET" || hc_tx_failed=1
    fi
    if [ "${HC_TX_WEB_BACKUP:-0}" -eq 1 ]; then
        docker exec "$HC_TX_WEB" cp -a "$HC_TX_BACKUP/runtime" "$HC_TX_TARGET" || hc_tx_failed=1
    fi
    if [ "$hc_tx_failed" -eq 0 ]; then
        # updateApp může změnit jen číslo verze; po vrácení kódu vraťme i tuto hodnotu.
        docker exec -u www-data "$HC_TX_WEB" php -r 'require "/var/www/html/lib/base.php"; $c=\OC::$server->get(\OCP\IConfig::class); if ($argv[2] === "") { $c->deleteAppValue($argv[1], "installed_version"); } else { $c->setAppValue($argv[1], "installed_version", $argv[2]); }' "$HC_TX_ID" "$HC_TX_OLD_VERSION" || hc_tx_failed=1
        if [ "$HC_TX_OLD_ENABLED" -eq 1 ] && docker exec "$HC_TX_WEB" test -d "$HC_TX_TARGET"; then
            docker exec -u www-data "$HC_TX_WEB" php /var/www/html/occ app:enable "$HC_TX_ID" || hc_tx_failed=1
        fi
        # Po vrácení souborů obnovit také PHP cache webového procesu bez restartu kontejneru.
        if docker exec "$HC_TX_WEB" sh -c 'command -v apache2ctl >/dev/null 2>&1'; then
            docker exec "$HC_TX_WEB" apache2ctl -k graceful >/dev/null 2>&1 || hc_tx_failed=1
        elif docker exec "$HC_TX_WEB" sh -c 'test -r /proc/1/comm && grep -qi php-fpm /proc/1/comm'; then
            docker exec "$HC_TX_WEB" kill -USR2 1 >/dev/null 2>&1 || hc_tx_failed=1
        fi
    fi
    if [ "$hc_tx_failed" -ne 0 ]; then
        printf 'ERROR: Automatická obnova se nezdařila; záloha kódu zůstává v %s.\n' "$HC_TX_BACKUP" >&2
        return 1
    fi
    printf 'Předchozí kód a stav aplikace byly obnoveny; záloha zůstává v %s.\n' "$HC_TX_BACKUP" >&2
}
