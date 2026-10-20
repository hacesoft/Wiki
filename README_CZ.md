# Home Wiki 1.0.0


Home Wiki je Markdown Wiki pro **Nextcloud 35**. Stránky, obrázky a přílohy se ukládají jako soubory do Nextcloud Files vlastníka; databáze Nextcloudu obsahuje strom stránek, sdílení, štítky a další metadata.

[English](README.md) · [Česká dokumentace](docs/cz/README.md) · [English documentation](docs/en/README.md)

## Požadavky

- Nextcloud **35**
- PHP **8.3–8.5** s rozšířením ZIP
- MariaDB/MySQL přes databázový ovladač Nextcloudu `mysql`
- [Shared App Core](https://github.com/hacesoft/core) (`hc_shared_app_core`) **0.18.8 nebo novější**, nainstalované a povolené předem

## Instalace

### Instalace z tohoto zdrojového projektu

Zdrojové soubory aplikace jsou v adresáři `src/`. Celý repozitář proto **nekopírujte přímo** do Nextcloud `custom_apps/`.

Nejprve nainstalujte a povolte HC Shared App Core. Na podporovaném Synology NAS s Dockerem stáhněte repozitář mimo `custom_apps` a spusťte z jeho kořene:

```sh
git clone https://github.com/hacesoft/Wiki.git
cd Wiki
sudo sh install.sh
```

Instalační skript nasazuje obsah `src/` pod ID `hc_homewiki` a zachovává data aplikace. Pro ostatní prostředí lze do `custom_apps/hc_homewiki/` umístit **pouze obsah `src/`**, nastavit správná oprávnění a zapnout aplikaci pomocí `occ app:enable hc_homewiki`. Před aktualizací zálohujte databázi i data. Podrobnosti: [Instalace a aktualizace](docs/cz/INSTALACE.md).

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

- `src/` – hotová aplikace (`src/appinfo/`, `src/lib/`, `src/js/`, `src/css/`, `src/l10n/`, `src/templates/`, `src/img/`)
- `docs/` – veřejná dokumentace v češtině a angličtině
- `install.sh`, `uninstall.sh`, `scripts/` – volitelné nástroje pro Docker/NAS, pro běžnou instalaci Nextcloudu nejsou potřeba
- `build-release.sh` – sestavení instalačních archivů
- `README.md`, `README_CZ.md`, `LICENSE`, `CHANGELOG.md` – dokumentace a licence

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
