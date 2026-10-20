<?php

declare(strict_types=1);

namespace OCA\HcHomeWiki\AppInfo;

use OCA\HcHomeWiki\Dashboard\HomeWikiWidget;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

final class Application extends App implements IBootstrap {
    public const APP_ID = 'hc_homewiki';
    public const VERSION = '1.0.0';
    public const REPOSITORY = 'https://github.com/hacesoft/Wiki';

    public function __construct() {
        // CZ-FUNKCE: Inicializuje objekt a jeho závislosti. Vstup: žádný přímý parametr. Výstup: bez samostatné návratové hodnoty; výsledkem je inicializovaný objekt.
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void {
        // CZ-FUNKCE: Registruje komponentu aplikace v Nextcloudu. Vstup: `$context`. Výstup: bez návratové hodnoty.
        $context->registerDashboardWidget(HomeWikiWidget::class);
    }

    public function boot(IBootContext $context): void {
        // CZ-FUNKCE: Provede startovací fázi aplikace po registraci. Vstup: `$context`. Výstup: bez návratové hodnoty.
    }
}
