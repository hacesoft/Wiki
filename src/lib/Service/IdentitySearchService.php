<?php

declare(strict_types=1);

namespace OCA\HcHomeWiki\Service;

use OCP\IGroupManager;
use OCP\IUserManager;

final class IdentitySearchService {
    public function __construct(
        private IUserManager $userManager,
        private IGroupManager $groupManager,
    ) {
        // CZ-FUNKCE: Inicializuje objekt a jeho závislosti. Vstup: `$userManager`, `$groupManager`. Výstup: bez samostatné návratové hodnoty; výsledkem je inicializovaný objekt.
    }

    public function search(string $query, array $types): array {
        // CZ-FUNKCE: Vyhledá odpovídající data. Vstup: `$query`, `$types`. Výstup: hodnota typu `array`.
        $needle = mb_substr(trim($query), 0, 255);
        $allowed = array_values(array_intersect($types, ['user', 'group']));
        if ($allowed === []) {
            $allowed = ['user', 'group'];
        }

        $items = [];
        if (in_array('user', $allowed, true)) {
            foreach ($this->userManager->search($needle, 40, 0) as $user) {
                $id = $user->getUID();
                $displayName = $user->getDisplayName();
                $items[] = [
                    'id' => $id,
                    'label' => $displayName !== '' ? $displayName : $id,
                    'type' => 'user',
                ];
            }
        }

        if (in_array('group', $allowed, true)) {
            foreach ($this->groupManager->search($needle, 40) as $group) {
                $id = $group->getGID();
                $displayName = $group->getDisplayName();
                $items[] = [
                    'id' => $id,
                    'label' => $displayName !== '' ? $displayName : $id,
                    'type' => 'group',
                ];
            }
        }

        return array_slice($items, 0, 80);
    }
}
