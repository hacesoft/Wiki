<?php

declare(strict_types=1);

namespace OCA\HcHomeWiki\BackgroundJob;

use OCA\HcHomeWiki\Service\WikiService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

final class TrashCleanupJob extends TimedJob {
    public function __construct(
        ITimeFactory $time,
        private WikiService $wikiService,
    ) {
        // CZ-FUNKCE: Inicializuje objekt a jeho závislosti. Vstup: `$time`, `$wikiService`. Výstup: bez samostatné návratové hodnoty; výsledkem je inicializovaný objekt.
        parent::__construct($time);
        $this->setInterval(24 * 60 * 60);
        $this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
        $this->setAllowParallelRuns(false);
    }

    protected function run($argument): void {
        // CZ-FUNKCE: Spustí danou úlohu. Vstup: `$argument`. Výstup: bez návratové hodnoty.
        $this->wikiService->cleanupExpiredTrash();
    }
}
