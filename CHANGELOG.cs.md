## 1.2.1

## 1.2.8

- Zvýšena verze, aby Nextcloud a prohlížeč jednoznačně načetly nové JS/CSS po úpravách responzivního rozložení.
- Funkční chování odpovídá finální variantě 1.2.7 s dotykovým rozložením podle FamilyTree.
- Projektová metadata `package.json` / `package-lock.json` byla sjednocena s `appinfo/info.xml`.

## 1.2.7
- Široké dotykové Android/iPad tablety zůstávají v overlay workspace režimu i naležato bez horního limitu šířky; boční ouško je připnuté ke středu viewportu a fokus jej neposouvá.
- Dolaďeno responzivní rozvržení telefonu/tabletu: strom stránek se otevírá jako překryvný panel přes boční ouško i na tabletu naležato.
- Editorový toolbar zůstává v jednom vodorovně posuvném řádku pro dotyk, kolečko i trackpad.

- Aktualizována veřejná dokumentace a README pro vydání 1.2.7.
- Přidána metadata screenshotů App Store pro `screenshots/01.png` a `screenshots/02.png`.
- Z `appinfo/info.xml` odstraněny duplicitní dokumentační odkazy; v prostředcích App Store zůstává jen domovská stránka.

## 1.2.5

- Mobile editor toolbar is now fixed directly above the content instead of floating over it.
- The formatting toolbar scrolls horizontally so all tools remain reachable on narrow screens.
- Mobile editor uses the maximum available viewport height with reduced top and bottom spacing.


## 1.2.4

- Opraveno mobilní tlačítko `⋯`, jehož nabídka se po kliknutí okamžitě zavírala.
- Samostatné mobilní tlačítko pro vytvoření stránky bylo odstraněno; vytvoření nové stránky je nyní v nabídce `⋯`.
- Mobilní nabídka obsahuje přímo editaci/zobrazení stránky, editorové nástroje, sdílení, historii, přílohy, štítky a další akce bez vnořeného menu.

## 1.2.3

- Mobilní rozhraní používá jeden responzivní horní řádek: Wiki, Zpět, Domů, Vpřed a jedno tlačítko nástrojů.
- Stejné kompaktní ovládání funguje na výšku, naležato i v tabletovém rozložení.
- Mobilní nabídka nástrojů se otevírá jako velká dotyková paleta od spodního okraje a při dostatečné šířce využije dva sloupce.

## 1.2.2

- Phone layout reduced to a single compact `Wiki` bar with one tools menu.
- Page title, metadata, page navigation and page action header no longer consume vertical space on phones.
- Mobile tools menu provides navigation, page creation, Back/Home/Forward, edit/view switching, editor toolbar access and page actions.
- Editor mode tabs are removed from the mobile layout; formatting controls appear only when requested.

- Mobile interface reduced for smaller screens.
- On phones, the page header is more compact and page actions are consolidated.
- In edit mode on phones, the editor toolbar opens from a floating button so the content stays visible.

## 1.2.0 — 2026-10-25
- Dokumentace znovu uvádí podporovanou Docker/full-source instalaci pomocí `sudo sh install.sh` vedle App Store a ruční instalace release archivu.
- Vyčištěna veřejná dokumentace a doplněn přehledný postup instalace z App Store i ruční instalace hotového release archivu.
- Přidána uživatelská volba počáteční pozice editoru: začátek nebo konec stránky; výchozí zůstává konec.
- Přidána volitelná obousměrná proporcionální synchronizace posouvání Markdown zdroje a živého náhledu; výchozí stav zůstává vypnuto.
- Počáteční pozice se nastaví pouze při skutečném vstupu do editace, takže autosave ani běžné překreslení uživatele neposouvá.

## 1.1.0-dev.7 — 2026-10-25
- Doplněna přesná dokumentace nativního formátu exportu/importu Home Wiki pro převod dat z jiných systémů.
- Popsán Markdown/Markdown-ZIP import, `formatVersion: 3`, mapování práv, médií, příloh a štítků, bezpečnostní limity a validace.
- Doplněn doporučený postup převodu s AI a znovupoužitelný prompt, který vyžaduje nejprve analýzu vstupního formátu.

## 1.1.0-dev.6 — 2026-10-25
- Přidána volitelná uživatelská navigace `← 🏠 →`, ve výchozím stavu vypnutá.
- Zpět/Vpřed používají pouze interní historii stránek Home Wiki; Domů otevře aktuální nastavenou úvodní stránku.
- Odstraněna duplicitní natvrdo zapsaná verze aplikace; UI a metadata exportu nyní čtou verzi z metadat Nextcloudu (`appinfo/info.xml`).
- Souhrn aktualizace nově porovnává velikost předchozího a nového runtime, pokud lze původní velikost bezpečně zjistit.

## 1.1.0-dev.4 — 2026-10-25
- Indikátory sdílení ve stromu jsou nově volitelné per-user nastavení a ve výchozím stavu jsou vypnuté.
- Nastavení Wiki je přeuspořádáno do logických skupin Úvodní stránka, Vzhled, Strom stránek a Výchozí nastavení správce.
- Vývojový instalátor nyní vypisuje jednotlivé kroky okamžitě během instalace místo souhrnu až na konci.

- Přidán kompaktní stav sdílení přímo do stromu stránek bez změny databáze nebo API.
- Strom rozlišuje soukromé stránky, sdílení s vybranými uživateli/skupinami a sdílení se všemi uživateli Nextcloudu.
- Zobrazuje efektivní oprávnění a stav trvalého dědění v podstromu; přesný rozpis ACL je dostupný v tooltipu.
- U smíšených práv více příjemců je stav označen `≠`, aby souhrn nebyl zavádějící.

## 1.1.0-dev.2 — 2026-10-24

- Oprava PHP upozornění `MigrationService.php#76` při aktivaci v Nextcloud 35: deklarace jediné databáze se parsovala jako řetězec.
- Beze změny databázového schématu a funkcí proti 1.1.0-dev.1.
- Doplněn kontrolní test manifestu.

## 1.1.0-dev.1 — 2026-10-24

- Trvalé dědění oprávnění v podstromu při vytvoření a přesunu stránky.
- Předání vlastnictví stránky nebo celé větve jinému uživateli, včetně souborů, obrázků a příloh.
- Při převodu se sdílení změní na soukromé a přemapují se štítky a aliasy.
- Nová přírůstková migrace `inherit_sharing` a funkční testy základních pravidel.

# Přehled změn

## 1.0.0 — 2026-10-20

První veřejné vydání zdrojových kódů pro Nextcloud 35.

### Funkce
- Markdown stránky, stromová navigace a fulltext
- Sdílení s oprávněními, přílohy a historie verzí
- Export/import celé Wiki a správa Koše

### Kompatibilita
- Nextcloud 35.
- Vyžaduje Hacesoft Shared App Core 0.18.8 nebo novější.
