<?php

declare(strict_types=1);

namespace OCA\HcHomeWiki\Controller;

use OCA\HcHomeWiki\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Util;

final class PageController extends Controller {
    public function __construct(
        IRequest $request,
        private IURLGenerator $urlGenerator,
    ) {
        // CZ-FUNKCE: Inicializuje objekt a jeho závislosti. Vstup: `$request`, `$urlGenerator`. Výstup: bez samostatné návratové hodnoty; výsledkem je inicializovaný objekt.
        parent::__construct(Application::APP_ID, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse {
        // CZ-FUNKCE: Připraví hlavní stránku aplikace. Vstup: žádný přímý parametr. Výstup: hodnota typu `TemplateResponse`.
        $dependency = $this->readCoreDependency();

        // Nextcloud owns deterministic asset ordering for the app and Shared App Core.
        Util::addStyle('hc_shared_app_core', 'workspace');
        Util::addStyle(Application::APP_ID, 'style');
        Util::addScript('hc_shared_app_core', 'hc_shared_app_core');
        Util::addScript(Application::APP_ID, 'app');

        return new TemplateResponse(Application::APP_ID, 'main', [
            'version' => Application::VERSION,
            'requiredCoreVersion' => $dependency['requiredVersion'],
            'requiredCoreApiVersion' => $dependency['requiredApiVersion'],
            'coreDownloadUrl' => $dependency['downloadUrl'],
        ]);
    }

    /** @return array{requiredVersion:string,requiredApiVersion:int,contract:string,downloadUrl:string} */
    private function readCoreDependency(): array {
        // CZ-FUNKCE: Načte požadovaná data. Vstup: žádný přímý parametr. Výstup: hodnota typu `array`.
        $path = dirname(__DIR__, 2) . '/appinfo/hc_shared_app_core.json';
        $raw = @file_get_contents($path);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)
            || !isset($data['requiredVersion'], $data['requiredApiVersion'], $data['contract'], $data['downloadUrl'])
            || !is_string($data['requiredVersion'])
            || !is_int($data['requiredApiVersion'])
            || $data['requiredApiVersion'] < 1
            || !is_string($data['contract'])
            || !is_string($data['downloadUrl'])) {
            throw new \RuntimeException('Invalid Shared App Core dependency manifest.');
        }

        return [
            'requiredVersion' => $data['requiredVersion'],
            'requiredApiVersion' => $data['requiredApiVersion'],
            'contract' => $data['contract'],
            'downloadUrl' => $data['downloadUrl'],
        ];
    }
}
