[Čeština](INSTALACE.md) · [English](../en/INSTALLATION.md)

# Instalace a aktualizace

## Požadavky

Home Wiki podporuje Nextcloud 35, PHP 8.3–8.5 a MariaDB/MySQL. Server musí mít PHP ZIP rozšíření. Aplikace vyžaduje Shared App Core (`hc_shared_app_core`) ve verzi 0.18.8 nebo novější.

Shared App Core nainstalujte a povolte dříve než Home Wiki.

## Instalace z GitHubu

Adresář aplikace musí odpovídat ID `hc_homewiki`. Zdrojové adresáře `appinfo/`, `lib/`, `js/` jsou přímo v kořeni tohoto repozitáře:

```bash
cd /var/www/html/custom_apps
git clone https://github.com/hacesoft/Wiki.git hc_homewiki
cd /var/www/html
sudo -u www-data php occ app:enable hc_homewiki
```

Při první instalaci Nextcloud automaticky spustí migraci z `lib/Migration/` a vytvoří potřebné tabulky a indexy. Není potřeba ručně vytvářet databázové tabulky.

Na produkčním Nextcloudu 35 nejsou vývojářské příkazy `migrations:status` a `migrations:migrate` standardně dostupné. Stav instalace ověřte například takto:

```bash
sudo -u www-data php occ config:app:get hc_homewiki installed_version
sudo -u www-data php occ app:list --enabled
```

## Ruční aktualizace

Pro Synology NAS / Docker lze použít samostatný skript `install.sh` spuštěný z kořene repozitáře mimo `custom_apps`.

Před aktualizací doporučujeme vytvořit nativní export Wiki a běžnou zálohu Nextcloudu. Potom nahraďte kód aplikace novou verzí při zachování adresáře `hc_homewiki`. Nextcloud při aktualizaci spouští všechny dosud neprovedené migrace.

Nemažte uživatelský adresář `HomeWiki/` ani databázové tabulky aplikace. Obsah stránek, média a přílohy jsou uživatelská data, ne instalační soubory aplikace.

## Background jobs

Automatické čištění Koše používá standardní Nextcloud background job. Pro běžný provoz má mít Nextcloud nakonfigurovaný systémový cron, nikoli pouze AJAX režim.

## Odinstalace

Zakázání aplikace samo o sobě nemaže uživatelský obsah. Před definitivním odstraněním dat použijte podle potřeby nativní export celé Wiki. Mazání dat provádějte vědomě přes funkce Home Wiki nebo standardní správu Nextcloudu.
