[Čeština](POUZITI.md) · [English](../en/USAGE.md)

# Používání Home Wiki

## Strom a navigace

Stránky jsou uspořádané do libovolně hlubokého stromu. Drag-and-drop lze zapnout v navigaci a stránku přesouvat mezi rodiči nebo na nejvyšší úroveň. Šířka levého panelu je na desktopu měnitelná. Dlouhé názvy se zkracují výpustkou; celý název je dostupný v tooltipu.

Sekce **Připnuté**, **Nedávné stránky**, **Často používané**, **Štítky** a **Stránky** lze sbalit nebo skrýt. Hustota menu a stromu je per-user nastavení (`Kompaktní` / `Pohodlné`).

## Editor a souběžné změny

Home Wiki používá editor a Markdown renderer Shared App Core. Čtecí režim, náhled a živý náhled proto používají stejný parser. Obsah se průběžně ukládá.

Každá stránka má číselnou revizi. Při uložení klient posílá očekávanou revizi a server provede atomickou kontrolu před změnou dat. Pokud mezitím stránku změnil jiný uživatel, aplikace změny nepřepíše naslepo a nabídne řešení konfliktu. Otevřená stránka navíc sleduje změny revize a čistý editor umí načíst novější stav.

## Interní odkazy

Wiki odkazy lze vkládat výběrem stránky. Přejmenování stránky zachovává historické aliasy, takže starší interní odkazy mohou dál najít cílovou stránku. Resolver vždy znovu kontroluje aktuální právo ke čtení cíle.

## Sdílení

Stránka může být:

- **Soukromá** – pouze vlastník,
- **Sdílená** – přístup určují ACL záznamy pro uživatele/skupiny,
- **Všichni uživatelé Nextcloudu** – všichni přihlášení mají minimálně Read; vyšší práva lze přidat explicitně.

Práva jsou oddělená na **Read, Edit, Delete, Share**. Vlastník má všechna práva. Edit umožňuje měnit obsah a přidávat přílohy; smazání přílohy vyžaduje Delete. Historie verzí je dostupná pouze uživatelům s Edit oprávněním.

## Obrázky a přílohy

Obrázky vložené editorem jsou uloženy v `HomeWiki/media/`. Obecné přílohy (PDF, ZIP, konfigurace, tabulky, logy atd.) jsou v `HomeWiki/attachments/`. Jedna příloha může mít maximálně 50 MB.

Read oprávnění dovoluje přílohu vypsat a stáhnout. Edit dovoluje nahrávat. Delete dovoluje mazat přílohy.

## Štítky

Štítky organizují stránky napříč stromem. Vlastník může štítek vytvořit, přejmenovat nebo odstranit a může jej přiřadit:

- pouze jedné stránce,
- stránce a všem jejím aktuálním potomkům.

Hromadné přiřazení podstromu není dědičnost: nová stránka vytvořená později štítek automaticky nepřevezme. Sdílení uživatelé mohou viditelné štítky používat pro filtrování, ale měnit je může pouze vlastník stránky.

## Historie verzí

Home Wiki nepoužívá vlastní duplicitní archiv obsahu. Historii Markdown souboru čte ze standardní aplikace Nextcloud `files_versions`. Retenci proto určuje konfigurace Nextcloudu. Obnova starší verze probíhá přes běžný save/CAS mechanismus Home Wiki.

## Nastavení

Každý uživatel může nastavit osobní úvodní stránku, viditelnost sekcí sidebaru, drag-and-drop, retenci Koše, hustotu menu/stromu a chování záhlaví stránky (`Posouvat s obsahem` / `Ponechat viditelné při posouvání`). Administrátor může nastavit výchozí úvodní stránku pro vlastníka nebo pro všechny uživatele; globální výchozí stránka musí být čitelná všemi přihlášenými uživateli.
