<?php

declare(strict_types=1);

namespace OCA\HcHomeWiki\Controller;

use OCA\HcHomeWiki\AppInfo\Application;
use OCA\HcHomeWiki\Service\IdentitySearchService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

final class UserController extends Controller {
    public function __construct(
        IRequest $request,
        private IdentitySearchService $identitySearchService,
    ) {
        // CZ-FUNKCE: Inicializuje objekt a jeho závislosti. Vstup: `$request`, `$identitySearchService`. Výstup: bez samostatné návratové hodnoty; výsledkem je inicializovaný objekt.
        parent::__construct(Application::APP_ID, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function search(string $q = '', string $query = '', string $types = 'user,group'): DataResponse {
        // CZ-FUNKCE: Vyhledá odpovídající data. Vstup: `$q`, `$query`, `$types`. Výstup: hodnota typu `DataResponse`.
        $needle = $query !== '' ? $query : $q;
        $allowedTypes = array_values(array_filter(array_map('trim', explode(',', $types))));
        $items = $this->identitySearchService->search($needle, $allowedTypes);

        return new DataResponse(['items' => $items]);
    }
}
