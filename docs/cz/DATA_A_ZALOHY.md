[Čeština](DATA_A_ZALOHY.md) · [English](../en/DATA_AND_BACKUP.md)

# Data, oprávnění, Koš a zálohy

## Uložení dat

Kanonický obsah stránky je Markdown v Nextcloud Files vlastníka:

```text
HomeWiki/
├─ .nomedia
├─ pages/
│  └─ <UUID>-<slug>.md
├─ media/
├─ attachments/
└─ .trash/
   ├─ pages/
   ├─ media/
   └─ attachments/
```

Markdown obsahuje interní `homewiki-meta` komentář s obnovovacími metadaty. Běžný text stránky zůstává standardní Markdown. `.nomedia` brání obecnému Preview Generatoru v asynchronním vytváření náhledů interních Wiki souborů.

Databáze drží autoritativní index a vztahy: strom/pořadí, viditelnost, ACL, aliasy, link index, revize, Koš a štítky. Fyzický obsah a databázová metadata se proto mají zálohovat společně.

## Databázové tabulky

Při instalaci je vytváří standardní Nextcloud migrace:

- `hc_homewiki_pages`
- `hc_homewiki_acl`
- `hc_homewiki_aliases`
- `hc_homewiki_links`
- `hc_homewiki_trash`
- `hc_homewiki_tags`
- `hc_homewiki_page_tags`

Nextcloud k názvům automaticky přidává systémový prefix databáze, například `oc_`.

## Delegované zápisy sdílených stránek

Sdílený uživatel může mít aplikační právo Edit k Markdownu, který fyzicky vlastní jiný uživatel. Home Wiki nejprve ověří vlastní ACL a pouze pro samotnou Files operaci dočasně použije filesystem kontext fyzického vlastníka. Tím vznikají standardní Nextcloud Files události a `files_versions` nad správným souborem. Persistentní přihlášení uživatele se tím nemění.

## Koš

Běžné smazání je vratné. Markdown, média a přílohy se přesunou do `HomeWiki/.trash/` a databáze uloží snapshot metadat, ACL, aliasů a štítků. Koš spravuje fyzický vlastník stránky.

Položku lze obnovit, trvale smazat nebo vyprázdnit celý vlastní Koš. Per-user automatická retence může být vypnutá nebo nastavená na 7, 14, 30 nebo 90 dní. Čištění provádí denní Nextcloud background job.

## Nativní export celé Wiki

Nativní export je ZIP s `manifest.json`. Exportují se **pouze aktivní stránky vlastněné aktuálním uživatelem**. Cizí stránky pouze nasdílené uživateli se do jeho backupu nepřidávají. Pokud uživatel nevlastní žádnou aktivní stránku, export není dostupný.

Aktuální formát je `formatVersion: 3`. Obsahuje:

- UUID stránky, parent, pořadí, titul, slug, pinned a revizi,
- aktuální Markdown,
- viditelnost, globální práva a ACL,
- historické aliasy,
- obrázky,
- obecné přílohy včetně SHA-256,
- definice štítků a jejich přiřazení.

Koš Home Wiki a historie `files_versions` se záměrně neexportují.

Nativní restore přijímá formáty v1, v2 a v3 a lze jej provést pouze do prázdné vlastní Wiki. Import kontroluje UUID, duplicity slugů, cykly stromu, bezpečné cesty v ZIPu, velikostní limity a dostupné kontrolní součty. ACL na uživatele nebo skupiny, které už neexistují, se přeskočí a počet přeskočených položek se oznámí.

## Markdown import

Samostatný `.md`/`.markdown` soubor se importuje jako nová soukromá stránka. ZIP bez Home Wiki manifestu je považován za Markdown adresář; importují se `.md`/`.markdown` soubory a hierarchie se odvozuje z `index.md` nebo odpovídající rodičovské Markdown stránky. Ostatní soubory v cizím Markdown ZIPu se ignorují – Home Wiki bez manifestu nehádá, ke které stránce mají patřit.

## Obnova z Markdown metadat

Recovery z Markdownu umí doplnit chybějící databázové řádky z interních `homewiki-meta` hlaviček. Existující záznamy nepřepisuje. Tato funkce je nouzová obnova indexu, nikoli náhrada kompletní zálohy Nextcloudu nebo nativního Home Wiki ZIPu.

## Smazání celé Wiki

`Smazat celou Wiki` odstraní aktivní i smazané stránky vlastněné aktuálním uživatelem a jejich Home Wiki soubory. Stránky jiných vlastníků, které jsou pouze nasdílené dovnitř, zůstávají nedotčené. Odchozí sdílení vlastních smazaných stránek samozřejmě zanikne spolu se zdrojovou stránkou.

Operace vyžaduje textové potvrzení a čerstvé aktuální heslo při každém provedení. Server používá strict password confirmation. Heslo se v Home Wiki neukládá.

## Ochrana importu

Před rozbalením ZIPu se kontrolují počty položek, jejich rozbalené velikosti, duplicitní cesty a souhrnné limity. Čtení má pevný limit; obnova ověřuje kontrolní součty a zpracovává obrázky a přílohy po jednom. Vytvoření nebo obnova stránek, import a smazání vlastní Wiki používají společný zámek Nextcloudu pro vlastníka. Při chybě obnovy se odstraňují pouze stránky vytvořené danou obnovou, nikdy celá složka HomeWiki. Zámek aplikace nepokrývá přímé úpravy souborů přes Files/WebDAV.
