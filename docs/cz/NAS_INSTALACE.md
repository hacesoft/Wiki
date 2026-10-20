[Čeština](NAS_INSTALACE.md) · [English](../en/INSTALLATION.md)

# Instalace Home Wiki 1.0.0 na Synology NAS / Docker

Tento postup je určen pro ruční instalaci a aktualizaci Home Wiki v prostředí Synology NAS / Docker s přístupem k příkazům Dockeru. Repozitář rozbalte mimo adresář `custom_apps`.

## Aktualizace nebo instalace

V rozbaleném kořeni balíku spusťte:

```sh
sudo sh install.sh
```

Instalátor:

- najde `nextcloud-app` a případný `nextcloud-cron`,
- ověří Nextcloud 35,
- ověří Shared App Core `0.18.8` nebo novější,
- vytvoří transakční zálohu aktuálního kódu aplikace,
- zkontroluje PHP syntaxi ještě před nasazením,
- nasadí obsah kořenových adresářů aplikace (`appinfo/`, `lib/`, `js/` atd.) do `custom_apps/hc_homewiki`,
- provede kontrolu databázového schématu a standardní Nextcloud update/install cestu,
- ověří `installed_version` a výslednou verzi na disku,
- při chybě se pokusí obnovit předchozí stav.

Uživatelské soubory `HomeWiki/` a existující databázová data se během běžné aktualizace nemažou.

## Odinstalace kódu

```sh
sudo sh uninstall.sh
```

Tím se odstraní pouze kód `hc_homewiki`; uživatelská data a Shared App Core zůstávají zachovány.

## Co instalátor používá

Instalátor nasazuje pouze potřebné kořenové adresáře aplikace. Pomocné skripty jsou v `scripts/`; dokumentace ani metadata repozitáře se do `custom_apps/hc_homewiki` přes tento postup nekopírují.
