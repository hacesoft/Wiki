<?php

declare(strict_types=1);

namespace OCA\HcHomeWiki\Dashboard;

use OCA\HcHomeWiki\AppInfo\Application;
use OCP\Dashboard\IWidget;
use OCP\IL10N;
use OCP\Util;

final class HomeWikiWidget implements IWidget {
    public function __construct(private IL10N $l10n) {
        // CZ-FUNKCE: Inicializuje objekt a jeho závislosti. Vstup: `$l10n`. Výstup: bez samostatné návratové hodnoty; výsledkem je inicializovaný objekt.
    }

    public function getId(): string {
        // CZ-FUNKCE: Načte a vrátí požadovaná data. Vstup: žádný přímý parametr. Výstup: hodnota typu `string`.
        return Application::APP_ID;
    }

    public function getTitle(): string {
        // CZ-FUNKCE: Načte a vrátí požadovaná data. Vstup: žádný přímý parametr. Výstup: hodnota typu `string`.
        return $this->l10n->t('Home Wiki');
    }

    public function getOrder(): int {
        // CZ-FUNKCE: Načte a vrátí požadovaná data. Vstup: žádný přímý parametr. Výstup: hodnota typu `int`.
        return 30;
    }

    public function getIconClass(): string {
        // CZ-FUNKCE: Načte a vrátí požadovaná data. Vstup: žádný přímý parametr. Výstup: hodnota typu `string`.
        return 'icon-filetype-text';
    }

    public function getUrl(): ?string {
        // CZ-FUNKCE: Načte a vrátí požadovaná data. Vstup: žádný přímý parametr. Výstup: hodnota typu `?string`.
        return null;
    }

    public function load(): void {
        // CZ-FUNKCE: Načte požadovaná data. Vstup: žádný přímý parametr. Výstup: bez návratové hodnoty.
        Util::addScript(Application::APP_ID, 'dashboard');
        Util::addStyle(Application::APP_ID, 'style');
    }
}
