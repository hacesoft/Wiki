# Home Wiki 1.0.0

> **Vstupní archiv pro Publisher:** Tento ZIP má výslednou strukturu s jediným kořenovým adresářem aplikace. Neobsahuje NAS nástroje (`install.sh`, `uninstall.sh`, `build-release.sh`, `scripts/`). Návody, které je zmiňují, platí pro samostatný úplný zdrojový repozitář, nikoli pro tento archiv. Podepsaný `.tar.gz` pro App Store vytváří až Hacesoft Publisher.


Home Wiki je Markdown Wiki pro **Nextcloud 35**. Stránky, obrázky a přílohy se ukládají jako soubory do Nextcloud Files vlastníka; databáze Nextcloudu obsahuje strom stránek, sdílení, štítky a další metadata.

[English](README.md) · [Česká dokumentace](docs/cz/README.md) · [English documentation](docs/en/README.md)

## Požadavky

- Nextcloud **35**
- PHP **8.3–8.5** s rozšířením ZIP
- MariaDB/MySQL přes databázový ovladač Nextcloudu `mysql`
- [Shared App Core](https://github.com/hacesoft/core) (`hc_shared_app_core`) **0.18.8 nebo novější**, nainstalované a povolené předem

## Instalace

### Standardní ruční instalace z GitHubu

Naklonujte repozitář přímo do `custom_apps` pod názvem **`hc_homewiki`**:

```sh
cd /var/www/html/custom_apps
git clone https://github.com/hacesoft/Wiki.git hc_homewiki
cd /var/www/html
sudo -u www-data php occ app:enable hc_homewiki
```

Soubory aplikace (`appinfo/`, `lib/`, `js/` atd.) jsou přímo **v kořeni repozitáře**, nikoliv v podadresáři `src/`. Lze také stáhnout ZIP se zdrojovými kódy, rozbalený adresář přejmenovat na `hc_homewiki`, vložit do `custom_apps/` a aplikaci povolit v Nextcloudu. Před aktualizací zálohujte Nextcloud. Podrobnosti: [Instalace a aktualizace](docs/cz/INSTALACE.md).

### Synology NAS / Docker

Volitelný instalační skript je určen pro prostředí s přístupem k příkazům Dockeru. Repozitář rozbalte či naklonujte **mimo** `custom_apps` a v jeho kořeni spusťte:

```sh
sudo sh install.sh
```

Instalátor vyhledá kontejnery Nextcloudu, ověří požadavky, zazálohuje stávající kód aplikace, nasadí nové soubory a ověří výsledek. Uživatelská data Wiki nemaže. Automatické vyhledání kontejnerů nemusí fungovat v každém prostředí. Viz [Instalace na NAS](docs/cz/NAS_INSTALACE.md).

`uninstall.sh` odstraní z Docker nasazení pouze kód aplikace; soubory uživatele a databázové záznamy zachová. Používejte jej jen s vědomím dopadů vypnutí/odebrání aplikace.

## Hlavní funkce

- Hierarchický strom stránek s drag-and-drop přesouváním
- Markdown editor ze Shared App Core, živý náhled a automatické ukládání
- Kontrola revizí a řešení konfliktů při souběžných změnách
- Soukromé, sdílené a všem přihlášeným dostupné stránky, samostatná práva Read / Edit / Delete / Share
- Wiki odkazy, aliasy, zpětné odkazy a fulltext
- Připnuté, nedávné a často používané stránky, štítky a přílohy
- Historie verzí přes `files_versions` Nextcloudu
- Koš s volitelnou retencí, ZIP export/import celé Wiki
- Widget na dashboardu, mobilní rozložení a nastavitelná navigace

## Struktura repozitáře

- `appinfo/`, `lib/`, `js/`, `css/`, `l10n/`, `templates/`, `img/` – soubory samotné aplikace
- `docs/` – veřejná dokumentace v češtině a angličtině
- `install.sh`, `uninstall.sh`, `scripts/` – volitelné nástroje pro Docker/NAS, pro běžnou instalaci Nextcloudu nejsou potřeba
- `README.md`, `README_CZ.md` – základní dokumentace

## Další dokumentace

- [Instalace](docs/cz/INSTALACE.md)
- [Instalace na NAS](docs/cz/NAS_INSTALACE.md)
- [Používání Wiki](docs/cz/POUZITI.md)
- [Data, sdílení, Koš a zálohy](docs/cz/DATA_A_ZALOHY.md)

## Jazyky

Čeština, angličtina, němčina, španělština, francouzština, italština, nizozemština, polština, portugalština (Portugalsko), slovenština a ukrajinština. Výchozí jazyk je angličtina.

## Licence

AGPL-3.0-or-later. Viz [LICENSE](LICENSE) a [úplné znění AGPL 3.0](https://www.gnu.org/licenses/agpl-3.0.txt).

Veřejná historie vydání: [CHANGELOG.md](CHANGELOG.md). Český přehled: [CHANGELOG.cs.md](CHANGELOG.cs.md).

## Úplný zdrojový balíček a Hacesoft Publisher

Balíček pro GitHub a instalaci na NAS obsahuje `install.sh`, `uninstall.sh`, `build-release.sh`, adresáře `scripts/`, `docs/` a hotovou aplikaci v `src/`. Pro instalaci na NAS rozbalte celý projekt a v jeho kořeni spusťte `sudo sh install.sh`. Do `custom_apps` se kopíruje pouze běhový obsah `src/`, nikoliv dokumentace projektu a instalační skripty. Publisher následně provede vlastní kontrolu a sestaví distribuční archiv s kořenovým adresářem podle ID aplikace; zdejší zdrojový ZIP není přímo distribuční TAR.GZ.
