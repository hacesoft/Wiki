[Čeština](INSTALACE.md) · [English](../en/INSTALLATION.md)

# Instalace a aktualizace

## Požadavky

Home Wiki podporuje Nextcloud 35, PHP 8.3–8.5 a MariaDB/MySQL. PHP musí mít rozšíření ZIP. Aplikace vyžaduje HC Shared App Core (`hc_shared_app_core`) 0.18.8 nebo novější.

Před Home Wiki nainstalujte a povolte HC Shared App Core.

## 1. Instalace z Nextcloud App Store

Pokud je Home Wiki dostupná v App Store vaší instance, otevřete **Aplikace**, vyhledejte **Home Wiki** a zvolte instalaci/povolení. Aktualizace se potom nabízejí standardním mechanismem Nextcloudu.

To je doporučená metoda pro běžné uživatele a správce.

## 2. Ruční instalace hotového release archivu

Ruční instalace nevyžaduje `npm`, Composer ani sestavení zdrojového kódu na serveru.

1. Stáhněte hotový release archiv `hc_homewiki-<verze>.tar.gz` nebo `hc_homewiki-<verze>.zip` z oficiálního vydání projektu.
2. Rozbalte archiv. Kořenová složka musí být přesně `hc_homewiki`.
3. Umístěte `hc_homewiki` do zapisovatelného adresáře aplikací Nextcloudu, obvykle `custom_apps/` nebo jiného adresáře definovaného v `apps_paths`.
4. Nastavte vlastnictví a oprávnění tak, aby kód aplikace mohl číst webový proces Nextcloudu.
5. Spusťte `occ` jako HTTP/web uživatel a aplikaci povolte.

Příklad pro běžnou Linux instalaci:

```sh
cd /var/www/nextcloud
sudo -u www-data php occ app:enable hc_homewiki
```

HTTP uživatel se liší podle distribuce (`www-data`, `apache`, `http`, `wwwrun` apod.).

Příklad pro Docker:

```sh
docker exec -u www-data <nextcloud-container> php /var/www/html/occ app:enable hc_homewiki
```

Název kontejneru a cesta k `occ` se mohou podle nasazení lišit.

Nextcloud při instalaci/aktivaci spustí migrace aplikace automaticky. Databázové tabulky nevytvářejte ručně.

## 3. Automatizovaná instalace full-source balíku přes `install.sh`

Full-source balík obsahuje také instalační skript pro Dockerová nasazení Nextcloudu. Tato možnost je vhodná pro správce nebo vývojové/servisní instalace, kde je požadována rychlá a kontrolovaná aktualizace bez ručního kopírování runtime souborů.

### Požadavky

- Linux/Unix shell s `sh`,
- oprávnění `root` přes `sudo`,
- příkaz `docker`,
- spuštěný Nextcloud web kontejner obsahující `/var/www/html/occ`,
- HC Shared App Core v požadované verzi.

Skript nejprve hledá běžné názvy kontejnerů `nextcloud-app` a `nextcloud-cron`; pokud je nenajde, zkusí automaticky vyhledat jiné běžící kontejnery Nextcloudu.

Po rozbalení full-source archivu přejděte do jeho kořene a spusťte:

```sh
sudo sh install.sh
```

Instalátor průběžně vypisuje jednotlivé kroky. Před nasazením nové verze kontroluje metadata a kompatibilitu, ověří Shared App Core, změří a zazálohuje předchozí runtime, validuje PHP, nasadí runtime do webového/cron kontejneru, zkontroluje databázové schéma a aktivuje aplikaci. Pokud se aktualizace nezdaří, transakční instalace se pokusí obnovit předchozí runtime a stav aplikace.

Záloha předchozího runtime se ukládá do datového adresáře Nextcloudu pod `hc-core-deployment-backups/`. Uživatelský adresář `HomeWiki/` není součástí runtime a instalační skript jej nemaže.

Tento skript je doplňková instalační cesta pro Docker. Není vyžadován pro instalaci z Nextcloud App Store ani pro ruční instalaci hotového release archivu.

## 4. Ověření instalace

Aktivní aplikaci a evidovanou verzi lze ověřit přes `occ`:

```sh
sudo -u www-data php occ app:list --enabled
sudo -u www-data php occ config:app:get hc_homewiki installed_version
```

## 5. Ruční aktualizace

Před aktualizací vytvořte nativní export Home Wiki a běžnou zálohu Nextcloudu.

1. Zakázat Home Wiki:

```sh
sudo -u www-data php occ app:disable hc_homewiki
```

2. Nahraďte **pouze kód aplikace** novou složkou `hc_homewiki` z release archivu.
3. Nemazat uživatelský adresář `HomeWiki/` ani databázové tabulky aplikace.
4. Znovu aplikaci povolte:

```sh
sudo -u www-data php occ app:enable hc_homewiki
```

5. Ověřte `installed_version` podle předchozí kapitoly.

Při aktivaci Nextcloud provede potřebné migrace nové verze.

## 6. Background jobs

Automatická retence Koše používá standardní Nextcloud background job. Pro běžný serverový provoz je doporučen systémový Cron namísto režimu AJAX.

## 7. Deaktivace a odstranění aplikace

Dočasné vypnutí:

```sh
sudo -u www-data php occ app:disable hc_homewiki
```

Zakázání aplikace samo o sobě nemaže uživatelské Wiki soubory. Pokud chcete aplikaci odstranit ručně, nejprve ji zakažte a potom odstraňte pouze adresář kódu `hc_homewiki` z adresáře aplikací.

Před definitivním odstraněním dat vytvořte export. Uživatelská data mažte pouze vědomě přes Home Wiki nebo správu Nextcloudu.

## Poznámka k adresářům aplikací

Nextcloud může mít více adresářů aplikací nastavených přes `apps_paths`. Při ruční instalaci používejte zapisovatelný adresář určený pro vlastní aplikace; neupravujte systémové aplikace dodané se serverem.
