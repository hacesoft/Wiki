[Čeština](FORMAT_IMPORT_EXPORT.md) · [English](../en/IMPORT_EXPORT_FORMAT.md)

# Formát exportu a importu Home Wiki

Tento dokument popisuje přenosný formát Home Wiki tak, aby bylo možné připravit import i z jiného wiki, poznámkového nebo dokumentačního systému. Nezávisí na konkrétní zdrojové aplikaci.

Home Wiki umí tři typy importu:

1. jeden soubor Markdown (`.md` nebo `.markdown`),
2. ZIP s běžnými Markdown soubory,
3. nativní Home Wiki ZIP s `manifest.json`.

Pro jednoduchý převod je nejbezpečnější Markdown nebo Markdown ZIP. Pokud je potřeba zachovat také strom, UUID, obrázky, přílohy, štítky, aliasy a oprávnění, použijte nativní formát.

> Nativní kompletní obnova je určena pro **prázdnou vlastní Wiki**. Před pokusem o import důležitých dat nejprve vytvořte zálohu Nextcloudu i Home Wiki export.

## 1. Nejjednodušší varianta: jeden Markdown soubor

Soubor `nazev-stranky.md` lze importovat přímo. Home Wiki vytvoří novou **soukromou** stránku.

Název stránky se určuje v tomto pořadí:

1. první Markdown nadpis `# Název`, pokud existuje,
2. jinak název souboru bez přípony.

Příklad:

```markdown
# Ukázkový server

Poznámky k serveru.
```

Interní odkaz Home Wiki lze zapisovat jako:

```text
[[Ukázkový server]]
```

## 2. Markdown ZIP

ZIP bez platného nativního `manifest.json` je zpracován jako obecný Markdown archiv. Importují se pouze `.md` a `.markdown` soubory. Ostatní soubory se ignorují.

Příklad struktury:

```text
import.zip
├─ Domaci-sit.md
├─ Domaci-sit/
│  ├─ Router.md
│  ├─ Switche.md
│  └─ Servery/
│     ├─ index.md
│     ├─ Ukazkovy-server.md
│     └─ Zaloha.md
└─ FVE.md
```

Home Wiki se pokusí odvodit strom z adresářů. Pro rodiče hledá zejména:

- `adresar/index.md`,
- `adresar/index.markdown`,
- `adresar.md`,
- `adresar.markdown`.

`index.md` se pojmenuje podle svého adresáře, pokud uvnitř nemá vlastní nadpis `# ...`.

Tato varianta je vhodná pro převod textů a stromu. **Neumí spolehlivě přiřadit cizí obrázky a přílohy ke stránkám**, protože bez manifestu není jednoznačné, komu patří. Pokud jsou obrázky, přílohy nebo metadata důležité, použijte nativní formát níže.

## 3. Nativní ZIP Home Wiki

Aktuální formát pro nově vytvářené archivy je:

```json
{
  "format": "hc-homewiki-export",
  "formatVersion": 3
}
```

Import umí kvůli starším vlastním zálohám načíst i `formatVersion` 1 a 2, ale **nový převod z cizích dat vždy vytvářejte jako verzi 3**.

### Struktura ZIPu

```text
HomeWiki-import.zip
├─ manifest.json
├─ pages/
│  ├─ 11111111-1111-4111-8111-111111111111.md
│  └─ 22222222-2222-4222-8222-222222222222.md
├─ media/
│  ├─ 11111111-1111-4111-8111-111111111111/
│  │  └─ schema.png
│  └─ 22222222-2222-4222-8222-222222222222/
│     └─ photo.jpg
└─ attachments/
   └─ 11111111-1111-4111-8111-111111111111/
      └─ manual.pdf
```

Každá stránka musí mít UUID verze 4. Cesta Markdownu musí být přesně:

```text
pages/<UUID>.md
```

Obrázky musí být pod:

```text
media/<UUID-stranky>/<nazev-souboru>
```

Přílohy musí být pod:

```text
attachments/<UUID-stranky>/<nazev-souboru>
```

Nepoužívejte absolutní cesty, `..`, zpětná lomítka ani duplicitní cesty v ZIPu.

## 4. `manifest.json`

Minimální praktický příklad nativního archivu se dvěma stránkami:

```json
{
  "format": "hc-homewiki-export",
  "formatVersion": 3,
  "appVersion": "1.2.0",
  "exportedAt": "2026-10-25T15:00:00Z",
  "sourceOwner": "import",
  "pageCount": 2,
  "mediaCount": 1,
  "attachmentCount": 1,
  "tagCount": 2,
  "tags": [
    { "name": "NAS" },
    { "name": "Síť" }
  ],
  "notes": {
    "trashExcluded": true,
    "nextcloudFileVersionsExcluded": true
  },
  "pages": [
    {
      "id": "11111111-1111-4111-8111-111111111111",
      "parentId": null,
      "title": "Síťová dokumentace",
      "slug": "sitova-dokumentace",
      "visibility": "private",
      "pinned": true,
      "sortOrder": 0,
      "createdAt": 1761400000,
      "updatedAt": 1761400000,
      "revision": 1,
      "allPermissions": {
        "read": false,
        "edit": false,
        "delete": false,
        "share": false
      },
      "inheritSharing": false,
      "acl": [],
      "aliases": ["sit"],
      "contentPath": "pages/11111111-1111-4111-8111-111111111111.md",
      "contentSha256": "<SHA-256 Markdown souboru>",
      "media": [
        {
          "name": "schema.png",
          "path": "media/11111111-1111-4111-8111-111111111111/schema.png",
          "mime": "image/png",
          "sha256": "<SHA-256 souboru>"
        }
      ],
      "attachments": [
        {
          "name": "manual.pdf",
          "path": "attachments/11111111-1111-4111-8111-111111111111/manual.pdf",
          "mime": "application/pdf",
          "size": 123456,
          "sha256": "<SHA-256 souboru>"
        }
      ],
      "tags": ["NAS", "Síť"]
    },
    {
      "id": "22222222-2222-4222-8222-222222222222",
      "parentId": "11111111-1111-4111-8111-111111111111",
      "title": "Ukázkový server",
      "slug": "ukazkovy-server",
      "visibility": "private",
      "pinned": false,
      "sortOrder": 10,
      "createdAt": 1761400000,
      "updatedAt": 1761400000,
      "revision": 1,
      "allPermissions": {
        "read": false,
        "edit": false,
        "delete": false,
        "share": false
      },
      "inheritSharing": false,
      "acl": [],
      "aliases": [],
      "contentPath": "pages/22222222-2222-4222-8222-222222222222.md",
      "contentSha256": "<SHA-256 Markdown souboru>",
      "media": [],
      "attachments": [],
      "tags": ["NAS"]
    }
  ]
}
```

Pole `appVersion`, `exportedAt`, `sourceOwner` a počítadla jsou užitečná pro diagnostiku a přenositelnost. Pro nový převod je doporučujeme vyplnit konzistentně, i když rozhodující pro obnovu jsou hlavně `format`, `formatVersion` a `pages`.

## 5. Pole stránky

| Pole | Význam |
| --- | --- |
| `id` | Jedinečné UUID v4 stránky. Nesmí se v archivu opakovat. |
| `parentId` | UUID rodiče nebo `null`. Rodič musí být součástí stejného importu. |
| `title` | Zobrazovaný název stránky. |
| `slug` | Jedinečná adresa stránky v rámci vlastníka. Pokud ji generujete, používejte jednoduché ASCII `a-z`, `0-9`, `-`, `_`, `/`. |
| `visibility` | `private`, `shared` nebo `all`. Pro migraci z neznámého systému doporučujeme `private`. |
| `pinned` | `true` / `false`. |
| `sortOrder` | Pořadí mezi sourozenci. Povolený rozsah je `-1000000` až `1000000`. |
| `createdAt`, `updatedAt` | Unix timestamp v sekundách. |
| `revision` | Kladné číslo revize, obvykle `1` při importu. |
| `allPermissions` | Práva všech přihlášených uživatelů při `visibility: "all"`. |
| `inheritSharing` | Zda se nastavení sdílení udržuje pro celý podstrom. |
| `acl` | Seznam konkrétních uživatelů a skupin. |
| `aliases` | Historické názvy/adresy stránky. |
| `contentPath` | Musí být přesně `pages/<id>.md`. |
| `contentSha256` | SHA-256 Markdownu. Není nutný pro načtení starších záloh, ale pro nově generovaný archiv jej vždy doporučujeme. |
| `media` | Obrázky patřící stránce. |
| `attachments` | Obecné přílohy patřící stránce. |
| `tags` | Názvy štítků přiřazených stránce. |

### Oprávnění pro všechny uživatele

Používají se pouze při `visibility: "all"`:

```json
"allPermissions": {
  "read": true,
  "edit": false,
  "delete": false,
  "share": false
}
```

Čtení je při `visibility: "all"` implicitní. Pro `private` a `shared` se globální práva resetují.

### ACL uživatelů a skupin

Příklad:

```json
"acl": [
  {
    "type": "user",
    "id": "jana",
    "canRead": true,
    "canEdit": true,
    "canDelete": false,
    "canShare": false
  },
  {
    "type": "group",
    "id": "family",
    "canRead": true,
    "canEdit": false,
    "canDelete": false,
    "canShare": false
  }
]
```

`type` je `user` nebo `group`. ID musí existovat v cílovém Nextcloudu. Neexistující uživatelé a skupiny se při importu přeskočí a Home Wiki oznámí počet přeskočených ACL položek.

Pokud při převodu neznáte přesné ekvivalenty práv, **nevymýšlejte je**. Nastavte stránku jako `private`, `acl: []` a práva doplňte až po importu.

## 6. Obrázky a přílohy

Každý soubor uvedený v manifestu musí fyzicky existovat v ZIPu pod přesnou cestou. Pro nově generované archivy vždy vytvářejte SHA-256.

Obrázek:

```json
{
  "name": "schema.png",
  "path": "media/<UUID>/schema.png",
  "mime": "image/png",
  "sha256": "..."
}
```

Příloha:

```json
{
  "name": "manual.pdf",
  "path": "attachments/<UUID>/manual.pdf",
  "mime": "application/pdf",
  "size": 123456,
  "sha256": "..."
}
```

Markdown musí odkazovat na média způsobem odpovídajícím výslednému obsahu. Pokud převodník nedokáže původní vazbu obrázku bezpečně určit, je lepší soubor neimportovat automaticky než jej přiřadit ke špatné stránce.

## 7. Štítky, aliasy a odkazy

Globální seznam štítků:

```json
"tags": [
  { "name": "NAS" },
  { "name": "Síť" }
]
```

Stránka potom obsahuje pouze názvy:

```json
"tags": ["NAS", "Síť"]
```

Názvy štítků mají maximálně 64 znaků.

Historické aliasy jsou prosté řetězce v `aliases`. Interní odkazy v Markdownu je vhodné při převodu převést na syntaxi:

```text
[[Název cílové stránky]]
```

Pokud zdroj používá vlastní ID odkazů, převodník by měl nejprve vytvořit mapu `zdrojové ID -> cílový název/UUID` a odkazy převést až ve druhém průchodu.

## 8. Bezpečnostní a velikostní limity

Aktuální implementace používá tyto limity. Mohou se v budoucí verzi změnit, proto je při tvorbě převodníku vhodné ověřit dokumentaci cílové verze.

| Limit | Hodnota |
| --- | ---: |
| Uploadovaný ZIP | 200 MiB |
| Počet položek ZIPu | 25 000 |
| Počet stránek | 2 000 |
| Jeden Markdown | 5 MiB |
| Součet Markdownů po rozbalení | 100 MiB |
| `manifest.json` | 10 MiB |
| Jeden obrázek/médium | 10 MiB |
| Počet médií | 5 000 |
| Součet médií po rozbalení | 250 MiB |
| Jedna příloha | 50 MiB |
| Počet příloh | 5 000 |
| Součet příloh po rozbalení | 500 MiB |

Home Wiki před čtením kontroluje deklarované rozbalené velikosti, duplicity cest a nebezpečné cesty a jednotlivé položky čte s pevnými limity.

## 9. Doporučený postup převodu z jiné aplikace

1. Udělejte kopii nebo export zdrojových dat. Nikdy nepřevádějte jedinou existující kopii.
2. Zjistěte skutečnou strukturu vstupu: stránky, ID, rodiče, názvy, Markdown/HTML, obrázky, přílohy, štítky a případná práva.
3. Rozhodněte, zda stačí Markdown ZIP, nebo potřebujete nativní `formatVersion: 3`.
4. Vytvořte stabilní mapu stránek a UUID v4. UUID po vytvoření už během stejného převodu neměňte.
5. Převádějte obsah. HTML je vhodné převést na běžný Markdown bez skriptů a bez nebezpečného vloženého HTML.
6. Ve druhém průchodu převeďte interní odkazy a vztahy rodič–potomek.
7. Teprve potom doplňte média, přílohy, štítky a případně oprávnění.
8. Spočítejte SHA-256 přes **skutečné bajty souborů uložených v ZIPu**.
9. Ověřte JSON, duplicity UUID/slugů, existenci všech cest a strom bez cyklů.
10. Import nejprve vyzkoušejte na prázdném testovacím uživateli/Wiki a ručně porovnejte výsledek se zdrojem.

## 10. Použití AI pro přípravu převodníku

AI nástroj může být pro jednorázovou migraci velmi užitečný, protože dokáže nejprve analyzovat neznámý export a potom vytvořit transformační skript. AI ale **nemá hádat strukturu vstupních dat**. Nejprve jí dejte vzorek nebo celý bezpečně zpřístupněný export a požadujte analýzu vstupního formátu.

Pokud zdrojová data obsahují soukromé poznámky, osobní údaje, hesla, tokeny nebo interní dokumentaci, nepředávejte je nedůvěryhodné veřejné AI službě. Použijte lokální nástroj, důvěryhodné firemní prostředí nebo data předem anonymizujte.

### Vzor promptu

```text
Mám export dat z jiné wiki/poznámkové aplikace a potřebuji jej převést do importního formátu Home Wiki.

Nejprve PROZKOUMEJ vstupní data. Nic nepředpokládej. Zjisti:
- kde jsou stránky a jejich obsah,
- zda mají stabilní ID,
- jak je uložen strom/rodiče a pořadí,
- jak jsou zapsané interní odkazy,
- kde jsou obrázky a přílohy a jak jsou propojené se stránkami,
- zda existují štítky, aliasy a oprávnění,
- zda je obsah Markdown, HTML nebo jiný formát.

Potom navrhni mapování do Home Wiki.

Pro jednoduchý převod můžeš vytvořit ZIP s .md/.markdown soubory a adresářovou strukturou.
Pro úplný převod vytvoř nativní ZIP Home Wiki formátu:
- manifest.json: format="hc-homewiki-export", formatVersion=3,
- Markdown stránky: pages/<UUIDv4>.md,
- obrázky: media/<UUIDv4>/<soubor>,
- přílohy: attachments/<UUIDv4>/<soubor>.

Každá stránka v manifestu má mít podle dostupných zdrojových dat:
id, parentId, title, slug, visibility, pinned, sortOrder, createdAt, updatedAt,
revision, allPermissions, inheritSharing, acl, aliases, contentPath,
contentSha256, media, attachments a tags.

Pravidla:
- Vygeneruj stabilní UUID verze 4 a použij je konzistentně v celém převodu.
- parentId smí odkazovat jen na stránku v témže importu nebo být null.
- contentPath musí být přesně pages/<id>.md.
- Nevymýšlej oprávnění. Pokud je nelze spolehlivě převést, použij visibility="private", acl=[] a prázdná globální práva.
- Neexistující nebo nejasné vazby raději vypiš jako varování místo jejich odhadování.
- Interní odkazy pokud možno převeď na [[Název stránky]].
- Převáděj HTML na čistý bezpečný Markdown; nepřenášej skripty ani aktivní obsah.
- Spočítej SHA-256 ze skutečných výsledných souborů.
- Ověř, že UUID i slugy jsou unikátní, strom nemá cyklus a všechny cesty z manifestu existují v ZIPu.
- Nevkládej do ZIPu žádné soubory, které nejsou pro import potřeba.

Nejdřív mi předlož analýzu vstupního formátu a mapovací tabulku. Až potom vytvoř transformační skript. Nakonec vypiš validační report: počet stránek, médií, příloh, štítků, nevyřešených odkazů a všech položek, které nebylo možné bezpečně převést.
```

Doporučení: nechte AI vytvořit **skript převodníku** (například Python), ne ručně přepisovat velký archiv. Skript lze znovu spustit, zkontrolovat a porovnat s původními daty.

## 11. Kontrola výsledného archivu před importem

Před importem ověřte alespoň:

- `manifest.json` je validní UTF-8 JSON,
- `format` je přesně `hc-homewiki-export`,
- `formatVersion` je `3`,
- každý `id` je unikátní UUID v4,
- každý `slug` je unikátní,
- každý `parentId` odkazuje na existující stránku v archivu nebo je `null`,
- strom neobsahuje cyklus,
- každý `contentPath`, `media[].path` a `attachments[].path` v ZIPu skutečně existuje,
- SHA-256 odpovídá skutečným bajtům souboru,
- názvy souborů v `media` a `attachments` neobsahují cestu,
- nejsou přítomné absolutní cesty ani `..`,
- soubor splňuje aktuální velikostní limity,
- cílová Wiki je při nativní kompletní obnově prázdná.

Po importu ručně zkontrolujte strom, několik interních odkazů, obrázky, přílohy, štítky a – pokud byly převáděny – také sdílení a oprávnění.
