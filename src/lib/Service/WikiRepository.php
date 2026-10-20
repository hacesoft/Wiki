<?php

declare(strict_types=1);

namespace OCA\HcHomeWiki\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;

final class WikiRepository {
    private const TABLE_PAGES = 'hc_homewiki_pages';
    private const TABLE_ACL = 'hc_homewiki_acl';
    private const TABLE_ALIASES = 'hc_homewiki_aliases';
    private const TABLE_LINKS = 'hc_homewiki_links';
    private const TABLE_TRASH = 'hc_homewiki_trash';
    private const TABLE_TAGS = 'hc_homewiki_tags';
    private const TABLE_PAGE_TAGS = 'hc_homewiki_page_tags';

    public function __construct(
        private IDBConnection $db,
        private IGroupManager $groupManager,
        private IUserManager $userManager,
    ) {
        // CZ-FUNKCE: Inicializuje objekt a jeho závislosti. Vstup: `$db`, `$groupManager`, `$userManager`. Výstup: bez samostatné návratové hodnoty; výsledkem je inicializovaný objekt.
    }

    public function newId(): string {
        // CZ-FUNKCE: Provádí dílčí operaci `newId` v rámci Home Wiki. Vstup: žádný přímý parametr. Výstup: hodnota typu `string`.
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-'
            . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-'
            . substr($hex, 20);
    }

    public function transactional(callable $operation): void {
        // CZ-FUNKCE: Provádí dílčí operaci `transactional` v rámci Home Wiki. Vstup: `$operation`. Výstup: bez návratové hodnoty.
        $this->db->beginTransaction();
        try {
            $operation();
            $this->db->commit();
        } catch (\Throwable $error) {
            try { $this->db->rollBack(); } catch (\Throwable) { /* Preserve the original failure. */ }
            throw $error;
        }
    }

    public function listVisible(string $uid): array {
        // CZ-FUNKCE: Vrací seznam dat pro danou operaci. Vstup: `$uid`. Výstup: hodnota typu `array`.
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE_PAGES)
            ->orderBy('sort_order', 'ASC')
            ->addOrderBy('title', 'ASC');

        $rows = $qb->executeQuery()->fetchAllAssociative();
        $decorated = array_map(fn(array $row): array => $this->decorate($row, $uid), $rows);

        return array_values(array_filter(
            $decorated,
            static fn(array $row): bool => (bool)$row['permissions']['read'],
        ));
    }

    public function get(string $id, string $uid): ?array {
        // CZ-FUNKCE: Načte a vrátí požadovaná data. Vstup: `$id`, `$uid`. Výstup: hodnota typu `?array`.
        $row = $this->getRaw($id);
        if ($row === null) {
            return null;
        }

        $decorated = $this->decorate($row, $uid);
        return $decorated['permissions']['read'] ? $decorated : null;
    }

    public function getRaw(string $id): ?array {
        // CZ-FUNKCE: Načte a vrátí požadovaná data. Vstup: `$id`. Výstup: hodnota typu `?array`.
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE_PAGES)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

        $row = $qb->executeQuery()->fetchAssociative();
        return $row === false ? null : $row;
    }

    public function getOwned(string $id, string $uid): ?array {
        // CZ-FUNKCE: Načte a vrátí požadovaná data. Vstup: `$id`, `$uid`. Výstup: hodnota typu `?array`.
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE_PAGES)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->andWhere($qb->expr()->eq('owner_uid', $qb->createNamedParameter($uid)));

        $row = $qb->executeQuery()->fetchAssociative();
        return $row === false ? null : $row;
    }

    public function slugExistsForOwner(string $ownerUid, string $slug, ?string $excludeId = null): bool {
        // CZ-FUNKCE: Provádí dílčí operaci `slugExistsForOwner` v rámci Home Wiki. Vstup: `$ownerUid`, `$slug`, `$excludeId`. Výstup: hodnota typu `bool`.
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from(self::TABLE_PAGES)
            ->where($qb->expr()->eq('owner_uid', $qb->createNamedParameter($ownerUid)))
            ->andWhere($qb->expr()->eq('slug', $qb->createNamedParameter($slug)));
        if ($excludeId !== null) {
            $qb->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($excludeId)));
        }

        return $qb->executeQuery()->fetchOne() !== false;
    }

    public function insert(array $page): void {
        // CZ-FUNKCE: Provádí dílčí operaci `insert` v rámci Home Wiki. Vstup: `$page`. Výstup: bez návratové hodnoty.
        $qb = $this->db->getQueryBuilder();
        $qb->insert(self::TABLE_PAGES)->values([
            'id' => $qb->createNamedParameter($page['id']),
            'owner_uid' => $qb->createNamedParameter($page['owner_uid']),
            'parent_id' => $qb->createNamedParameter($page['parent_id']),
            'title' => $qb->createNamedParameter($page['title']),
            'slug' => $qb->createNamedParameter($page['slug']),
            'file_path' => $qb->createNamedParameter($page['file_path']),
            'visibility' => $qb->createNamedParameter($page['visibility']),
            'pinned' => $qb->createNamedParameter((int)(bool)$page['pinned'], IQueryBuilder::PARAM_INT),
            'sort_order' => $qb->createNamedParameter((int)$page['sort_order'], IQueryBuilder::PARAM_INT),
            'created_at' => $qb->createNamedParameter((int)$page['created_at'], IQueryBuilder::PARAM_INT),
            'updated_at' => $qb->createNamedParameter((int)$page['updated_at'], IQueryBuilder::PARAM_INT),
            'revision' => $qb->createNamedParameter((int)($page['revision'] ?? 1), IQueryBuilder::PARAM_INT),
            'all_can_edit' => $qb->createNamedParameter((int)(bool)($page['all_can_edit'] ?? false), IQueryBuilder::PARAM_INT),
            'all_can_delete' => $qb->createNamedParameter((int)(bool)($page['all_can_delete'] ?? false), IQueryBuilder::PARAM_INT),
            'all_can_share' => $qb->createNamedParameter((int)(bool)($page['all_can_share'] ?? false), IQueryBuilder::PARAM_INT),
        ])->executeStatement();

        $this->addAlias($page['id'], $page['owner_uid'], (string)$page['slug']);
        $this->addAlias($page['id'], $page['owner_uid'], (string)$page['title']);
    }

    /**
     * Atomically updates one page if the caller still owns the expected revision.
     * Returns false when another mutation already advanced the page revision.
     */
    public function updateCompareAndSwap(string $id, int $expectedRevision, array $page): bool {
        // CZ-FUNKCE: Aktualizuje existující data. Vstup: `$id`, `$expectedRevision`, `$page`. Výstup: hodnota typu `bool`.
        $nextRevision = $expectedRevision + 1;
        $qb = $this->db->getQueryBuilder();
        $changed = $qb->update(self::TABLE_PAGES)
            ->set('parent_id', $qb->createNamedParameter($page['parent_id']))
            ->set('title', $qb->createNamedParameter($page['title']))
            ->set('slug', $qb->createNamedParameter($page['slug']))
            ->set('file_path', $qb->createNamedParameter($page['file_path']))
            ->set('visibility', $qb->createNamedParameter($page['visibility']))
            ->set('pinned', $qb->createNamedParameter((int)(bool)$page['pinned'], IQueryBuilder::PARAM_INT))
            ->set('sort_order', $qb->createNamedParameter((int)$page['sort_order'], IQueryBuilder::PARAM_INT))
            ->set('updated_at', $qb->createNamedParameter((int)($page['updated_at'] ?? time()), IQueryBuilder::PARAM_INT))
            ->set('revision', $qb->createNamedParameter($nextRevision, IQueryBuilder::PARAM_INT))
            ->set('all_can_edit', $qb->createNamedParameter((int)(bool)($page['all_can_edit'] ?? false), IQueryBuilder::PARAM_INT))
            ->set('all_can_delete', $qb->createNamedParameter((int)(bool)($page['all_can_delete'] ?? false), IQueryBuilder::PARAM_INT))
            ->set('all_can_share', $qb->createNamedParameter((int)(bool)($page['all_can_share'] ?? false), IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->andWhere($qb->expr()->eq('revision', $qb->createNamedParameter($expectedRevision, IQueryBuilder::PARAM_INT)))
            ->executeStatement();

        if ($changed !== 1) {
            return false;
        }

        $this->addAlias($id, (string)$page['owner_uid'], (string)$page['slug']);
        $this->addAlias($id, (string)$page['owner_uid'], (string)$page['title']);
        return true;
    }

    /** Recovery-only parent repair; normal interactive mutations must use CAS. */
    public function updateParentForRecovery(string $id, ?string $parentId): void {
        // CZ-FUNKCE: Aktualizuje existující data. Vstup: `$id`, `$parentId`. Výstup: bez návratové hodnoty.
        $qb = $this->db->getQueryBuilder();
        $qb->update(self::TABLE_PAGES)
            ->set('parent_id', $qb->createNamedParameter($parentId))
            ->set('revision', $qb->func()->add('revision', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
            ->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->executeStatement();
    }

    /** Locks a revision for delete by advancing it in the current transaction. */
    public function claimRevision(string $id, int $expectedRevision): bool {
        // CZ-FUNKCE: Provádí dílčí operaci `claimRevision` v rámci Home Wiki. Vstup: `$id`, `$expectedRevision`. Výstup: hodnota typu `bool`.
        $qb = $this->db->getQueryBuilder();
        $changed = $qb->update(self::TABLE_PAGES)
            ->set('revision', $qb->createNamedParameter($expectedRevision + 1, IQueryBuilder::PARAM_INT))
            ->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->andWhere($qb->expr()->eq('revision', $qb->createNamedParameter($expectedRevision, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
        return $changed === 1;
    }

    /** @return list<string> */
    public function getAliases(string $pageId): array {
        // CZ-FUNKCE: Načte a vrátí požadovaná data. Vstup: `$pageId`. Výstup: hodnota typu `array`.
        $qb = $this->db->getQueryBuilder();
        $qb->select('alias')
            ->from(self::TABLE_ALIASES)
            ->where($qb->expr()->eq('page_id', $qb->createNamedParameter($pageId)))
            ->orderBy('id', 'ASC');

        return array_values(array_map('strval', $qb->executeQuery()->fetchFirstColumn()));
    }

    /** Store one deleted live page before its live metadata is removed. */
    public function insertTrash(array $trash): void {
        // CZ-FUNKCE: Provádí dílčí operaci `insertTrash` v rámci Home Wiki. Vstup: `$trash`. Výstup: bez návratové hodnoty.
        $qb = $this->db->getQueryBuilder();
        $qb->insert(self::TABLE_TRASH)->values([
            'id' => $qb->createNamedParameter((string)$trash['id']),
            'owner_uid' => $qb->createNamedParameter((string)$trash['owner_uid']),
            'title' => $qb->createNamedParameter((string)$trash['title']),
            'trash_path' => $qb->createNamedParameter((string)$trash['trash_path']),
            'page_json' => $qb->createNamedParameter((string)$trash['page_json']),
            'acl_json' => $qb->createNamedParameter((string)$trash['acl_json']),
            'aliases_json' => $qb->createNamedParameter((string)$trash['aliases_json']),
            'deleted_at' => $qb->createNamedParameter((int)$trash['deleted_at'], IQueryBuilder::PARAM_INT),
            'deleted_by' => $qb->createNamedParameter((string)$trash['deleted_by']),
        ])->executeStatement();
    }

    public function listTrashOwned(string $uid): array {
        // CZ-FUNKCE: Vrací seznam dat pro danou operaci. Vstup: `$uid`. Výstup: hodnota typu `array`.
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'title', 'deleted_at', 'deleted_by')
            ->from(self::TABLE_TRASH)
            ->where($qb->expr()->eq('owner_uid', $qb->createNamedParameter($uid)))
            ->orderBy('deleted_at', 'DESC')
            ->addOrderBy('title', 'ASC');

        return array_map(static fn(array $row): array => [
            'id' => (string)$row['id'],
            'title' => (string)$row['title'],
            'deleted_at' => (int)$row['deleted_at'],
            'deleted_by' => (string)$row['deleted_by'],
        ], $qb->executeQuery()->fetchAllAssociative());
    }

    public function getTrashOwned(string $id, string $uid): ?array {
        // CZ-FUNKCE: Načte a vrátí požadovaná data. Vstup: `$id`, `$uid`. Výstup: hodnota typu `?array`.
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE_TRASH)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->andWhere($qb->expr()->eq('owner_uid', $qb->createNamedParameter($uid)));
        $row = $qb->executeQuery()->fetchAssociative();
        return $row === false ? null : $row;
    }

    public function deleteTrashRecord(string $id): void {
        // CZ-FUNKCE: Smaže požadovaná data. Vstup: `$id`. Výstup: bez návratové hodnoty.
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE_TRASH)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->executeStatement();
    }

    /**
     * Removes a page from the live index while intentionally preserving incoming
     * link rows (target_page_id). They become valid backlinks again after restore.
     */
    public function deleteForTrash(string $id): void {
        // CZ-FUNKCE: Smaže požadovaná data. Vstup: `$id`. Výstup: bez návratové hodnoty.
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE_LINKS)
            ->where($qb->expr()->eq('source_page_id', $qb->createNamedParameter($id)))
            ->executeStatement();

        foreach ([self::TABLE_ALIASES, self::TABLE_ACL, self::TABLE_PAGE_TAGS] as $table) {
            $qb = $this->db->getQueryBuilder();
            $qb->delete($table)
                ->where($qb->expr()->eq('page_id', $qb->createNamedParameter($id)))
                ->executeStatement();
        }

        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE_PAGES)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->executeStatement();
    }

    /** @param list<string> $aliases */
    public function restoreAliases(string $pageId, string $ownerUid, array $aliases): void {
        // CZ-FUNKCE: Obnoví dříve odstraněná nebo verzovaná data. Vstup: `$pageId`, `$ownerUid`, `$aliases`. Výstup: bez návratové hodnoty.
        foreach ($aliases as $alias) {
            if (is_string($alias)) {
                $this->addAlias($pageId, $ownerUid, $alias);
            }
        }
    }

    public function removeIncomingLinks(string $id): void {
        // CZ-FUNKCE: Odstraní požadovaná data nebo vazbu. Vstup: `$id`. Výstup: bez návratové hodnoty.
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE_LINKS)
            ->where($qb->expr()->eq('target_page_id', $qb->createNamedParameter($id)))
            ->executeStatement();
    }

    /** Re-parent currently live children when a trashed page is deleted permanently. */
    public function reparentChildren(string $ownerUid, string $oldParentId, ?string $newParentId): int {
        // CZ-FUNKCE: Provádí dílčí operaci `reparentChildren` v rámci Home Wiki. Vstup: `$ownerUid`, `$oldParentId`, `$newParentId`. Výstup: hodnota typu `int`.
        $qb = $this->db->getQueryBuilder();
        return $qb->update(self::TABLE_PAGES)
            ->set('parent_id', $qb->createNamedParameter($newParentId))
            ->set('revision', $qb->func()->add('revision', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
            ->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('owner_uid', $qb->createNamedParameter($ownerUid)))
            ->andWhere($qb->expr()->eq('parent_id', $qb->createNamedParameter($oldParentId)))
            ->executeStatement();
    }

    public function delete(string $id): void {
        // CZ-FUNKCE: Smaže požadovaná data. Vstup: `$id`. Výstup: bez návratové hodnoty.
        foreach ([
            self::TABLE_LINKS => ['source_page_id', 'target_page_id'],
            self::TABLE_ALIASES => ['page_id'],
            self::TABLE_ACL => ['page_id'],
            self::TABLE_PAGE_TAGS => ['page_id'],
        ] as $table => $columns) {
            foreach ($columns as $column) {
                $qb = $this->db->getQueryBuilder();
                $qb->delete($table)
                    ->where($qb->expr()->eq($column, $qb->createNamedParameter($id)))
                    ->executeStatement();
            }
        }

        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE_PAGES)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->executeStatement();
    }

    /** @return list<string> */
    public function descendantIds(string $ownerUid, string $rootId): array {
        // CZ-FUNKCE: Provádí dílčí operaci `descendantIds` v rámci Home Wiki. Vstup: `$ownerUid`, `$rootId`. Výstup: hodnota typu `array`.
        $qb = $this->db->getQueryBuilder();
        $rows = $qb->select('id', 'parent_id')
            ->from(self::TABLE_PAGES)
            ->where($qb->expr()->eq('owner_uid', $qb->createNamedParameter($ownerUid)))
            ->executeQuery()->fetchAllAssociative();

        $children = [];
        foreach ($rows as $row) {
            $parentId = $row['parent_id'] !== null ? (string)$row['parent_id'] : '';
            if ($parentId === '') {
                continue;
            }
            $children[$parentId][] = (string)$row['id'];
        }

        $result = [];
        $queue = $children[$rootId] ?? [];
        $seen = [$rootId => true];
        while ($queue !== []) {
            $id = array_shift($queue);
            if (!is_string($id) || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $result[] = $id;
            foreach ($children[$id] ?? [] as $childId) {
                if (!isset($seen[$childId])) {
                    $queue[] = $childId;
                }
            }
        }

        return $result;
    }

    public function replaceAcl(string $pageId, array $acl): void {
        // CZ-FUNKCE: Provádí dílčí operaci `replaceAcl` v rámci Home Wiki. Vstup: `$pageId`, `$acl`. Výstup: bez návratové hodnoty.
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE_ACL)
            ->where($qb->expr()->eq('page_id', $qb->createNamedParameter($pageId)))
            ->executeStatement();

        foreach ($acl as $entry) {
            $qb = $this->db->getQueryBuilder();
            $qb->insert(self::TABLE_ACL)->values([
                'page_id' => $qb->createNamedParameter($pageId),
                'subject_type' => $qb->createNamedParameter($entry['type']),
                'subject_id' => $qb->createNamedParameter($entry['id']),
                'can_read' => $qb->createNamedParameter((int)(bool)$entry['canRead'], IQueryBuilder::PARAM_INT),
                'can_edit' => $qb->createNamedParameter((int)(bool)$entry['canEdit'], IQueryBuilder::PARAM_INT),
                'can_delete' => $qb->createNamedParameter((int)(bool)$entry['canDelete'], IQueryBuilder::PARAM_INT),
                'can_share' => $qb->createNamedParameter((int)(bool)$entry['canShare'], IQueryBuilder::PARAM_INT),
            ])->executeStatement();
        }
    }

    public function getAcl(string $pageId): array {
        // CZ-FUNKCE: Načte a vrátí požadovaná data. Vstup: `$pageId`. Výstup: hodnota typu `array`.
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE_ACL)
            ->where($qb->expr()->eq('page_id', $qb->createNamedParameter($pageId)));

        return array_map(
            static fn(array $row): array => [
                'type' => $row['subject_type'],
                'id' => $row['subject_id'],
                'canRead' => array_key_exists('can_read', $row) ? (bool)$row['can_read'] : true,
                'canEdit' => (bool)$row['can_edit'],
                'canDelete' => array_key_exists('can_delete', $row) ? (bool)$row['can_delete'] : false,
                'canShare' => (bool)$row['can_share'],
            ],
            $qb->executeQuery()->fetchAllAssociative(),
        );
    }

    public function rebuildLinks(string $pageId, string $ownerUid, string $content): void {
        // CZ-FUNKCE: Provádí dílčí operaci `rebuildLinks` v rámci Home Wiki. Vstup: `$pageId`, `$ownerUid`, `$content`. Výstup: bez návratové hodnoty.
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE_LINKS)
            ->where($qb->expr()->eq('source_page_id', $qb->createNamedParameter($pageId)))
            ->executeStatement();

        preg_match_all('/\[\[([^\]|]+)(?:\|[^\]]+)?\]\]/u', $content, $internalMatches);
        foreach (array_unique($internalMatches[1] ?? []) as $rawTarget) {
            $cleanTarget = trim((string)$rawTarget);
            $targetId = $this->resolveAlias($cleanTarget, $ownerUid);
            $this->insertLink($pageId, $targetId, $cleanTarget, 'internal');
        }

        preg_match_all('~\bhttps?://[^\s<>"\']+~iu', $content, $externalMatches);
        foreach (array_unique($externalMatches[0] ?? []) as $rawTarget) {
            $this->insertLink($pageId, null, (string)$rawTarget, 'external');
        }
    }

    public function backlinks(string $pageId, string $uid): array {
        // CZ-FUNKCE: Provádí dílčí operaci `backlinks` v rámci Home Wiki. Vstup: `$pageId`, `$uid`. Výstup: hodnota typu `array`.
        $qb = $this->db->getQueryBuilder();
        $qb->select('source_page_id')
            ->from(self::TABLE_LINKS)
            ->where($qb->expr()->eq('target_page_id', $qb->createNamedParameter($pageId)));

        $pages = [];
        foreach ($qb->executeQuery()->fetchFirstColumn() as $sourceId) {
            $page = $this->get((string)$sourceId, $uid);
            if ($page !== null) {
                $pages[] = $page;
            }
        }

        return $pages;
    }

    public function search(string $uid, string $query): array {
        // CZ-FUNKCE: Vyhledá odpovídající data. Vstup: `$uid`, `$query`. Výstup: hodnota typu `array`.
        $normalized = mb_strtolower(trim($query));
        if ($normalized === '') {
            return $this->listVisible($uid);
        }

        return array_values(array_filter(
            $this->listVisible($uid),
            static fn(array $page): bool => str_contains(
                mb_strtolower((string)$page['title'] . ' ' . (string)$page['slug']),
                $normalized,
            ),
        ));
    }

    /** Resolve an alias and then apply the normal read ACL for the requesting user. */
    public function resolveVisibleAlias(string $alias, string $ownerUid, string $uid): ?array {
        // CZ-FUNKCE: Vyřeší a vrátí odpovídající hodnotu. Vstup: `$alias`, `$ownerUid`, `$uid`. Výstup: hodnota typu `?array`.
        $pageId = $this->resolveAlias($alias, $ownerUid);
        return $pageId === null ? null : $this->get($pageId, $uid);
    }

    private function decorate(array $row, string $uid): array {
        // CZ-FUNKCE: Provádí dílčí operaci `decorate` v rámci Home Wiki. Vstup: `$row`, `$uid`. Výstup: hodnota typu `array`.
        $row['pinned'] = (bool)$row['pinned'];
        $row['sort_order'] = (int)$row['sort_order'];
        $row['created_at'] = (int)$row['created_at'];
        $row['updated_at'] = (int)$row['updated_at'];
        $row['revision'] = max(1, (int)($row['revision'] ?? 1));
        $row['all_can_edit'] = (bool)($row['all_can_edit'] ?? false);
        $row['all_can_delete'] = (bool)($row['all_can_delete'] ?? false);
        $row['all_can_share'] = (bool)($row['all_can_share'] ?? false);
        $row['allPermissions'] = [
            'read' => $row['visibility'] === 'all',
            'edit' => $row['visibility'] === 'all' && $row['all_can_edit'],
            'delete' => $row['visibility'] === 'all' && $row['all_can_delete'],
            'share' => $row['visibility'] === 'all' && $row['all_can_share'],
        ];
        $row['permissions'] = $this->permissions($row, $uid);
        $row['acl'] = $row['permissions']['share'] ? $this->getAcl((string)$row['id']) : [];
        $row['tags'] = $this->getTagsForPage((string)$row['id']);

        return $row;
    }

    private function permissions(array $page, string $uid): array {
        // CZ-FUNKCE: Provádí dílčí operaci `permissions` v rámci Home Wiki. Vstup: `$page`, `$uid`. Výstup: hodnota typu `array`.
        if ($page['owner_uid'] === $uid) {
            return ['read' => true, 'edit' => true, 'delete' => true, 'share' => true, 'owner' => true];
        }

        // visibility=all establishes the base permissions for every authenticated user.
        // Explicit user/group ACL rows are additive extensions and never revoke
        // rights already granted globally.
        $canRead = $page['visibility'] === 'all';
        $canEdit = $canRead && (bool)($page['all_can_edit'] ?? false);
        $canDelete = $canRead && (bool)($page['all_can_delete'] ?? false);
        $canShare = $canRead && (bool)($page['all_can_share'] ?? false);

        if ($page['visibility'] === 'private') {
            return ['read' => false, 'edit' => false, 'delete' => false, 'share' => false, 'owner' => false];
        }

        $user = $this->userManager->get($uid);
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE_ACL)
            ->where($qb->expr()->eq('page_id', $qb->createNamedParameter($page['id'])));

        foreach ($qb->executeQuery()->fetchAllAssociative() as $entry) {
            $matches = $entry['subject_type'] === 'user' && $entry['subject_id'] === $uid;
            if ($entry['subject_type'] === 'group' && $user !== null) {
                $group = $this->groupManager->get((string)$entry['subject_id']);
                $matches = $group !== null && $group->inGroup($user);
            }
            if ($matches) {
                $entryCanRead = array_key_exists('can_read', $entry) ? (bool)$entry['can_read'] : true;
                $canRead = $canRead || $entryCanRead;
                $canEdit = $canEdit || ($entryCanRead && (bool)$entry['can_edit']);
                $canDelete = $canDelete || ($entryCanRead && array_key_exists('can_delete', $entry) && (bool)$entry['can_delete']);
                $canShare = $canShare || ($entryCanRead && (bool)$entry['can_share']);
            }
        }

        return ['read' => $canRead, 'edit' => $canEdit, 'delete' => $canDelete, 'share' => $canShare, 'owner' => false];
    }


    /** @return list<array{id:string,owner_uid:string,name:string,normalized_name:string,created_at:int,updated_at:int,count:int}> */
    public function listTagsOwned(string $ownerUid): array {
        // CZ-FUNKCE: Vrací seznam dat pro danou operaci. Vstup: `$ownerUid`. Výstup: hodnota typu `array`.
        $qb = $this->db->getQueryBuilder();
        $rows = $qb->select('*')
            ->from(self::TABLE_TAGS)
            ->where($qb->expr()->eq('owner_uid', $qb->createNamedParameter($ownerUid)))
            ->orderBy('normalized_name', 'ASC')
            ->executeQuery()->fetchAllAssociative();

        $result = [];
        foreach ($rows as $row) {
            $countQb = $this->db->getQueryBuilder();
            $count = (int)$countQb->select($countQb->func()->count('id'))
                ->from(self::TABLE_PAGE_TAGS)
                ->where($countQb->expr()->eq('tag_id', $countQb->createNamedParameter((string)$row['id'])))
                ->executeQuery()->fetchOne();
            $result[] = [
                'id' => (string)$row['id'],
                'owner_uid' => (string)$row['owner_uid'],
                'name' => (string)$row['name'],
                'normalized_name' => (string)$row['normalized_name'],
                'created_at' => (int)$row['created_at'],
                'updated_at' => (int)$row['updated_at'],
                'count' => $count,
            ];
        }
        return $result;
    }

    public function getTagOwned(string $id, string $ownerUid): ?array {
        // CZ-FUNKCE: Načte a vrátí požadovaná data. Vstup: `$id`, `$ownerUid`. Výstup: hodnota typu `?array`.
        $qb = $this->db->getQueryBuilder();
        $row = $qb->select('*')
            ->from(self::TABLE_TAGS)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->andWhere($qb->expr()->eq('owner_uid', $qb->createNamedParameter($ownerUid)))
            ->executeQuery()->fetchAssociative();
        return $row === false ? null : $row;
    }

    public function getTagByNormalizedName(string $ownerUid, string $normalizedName): ?array {
        // CZ-FUNKCE: Načte a vrátí požadovaná data. Vstup: `$ownerUid`, `$normalizedName`. Výstup: hodnota typu `?array`.
        $qb = $this->db->getQueryBuilder();
        $row = $qb->select('*')
            ->from(self::TABLE_TAGS)
            ->where($qb->expr()->eq('owner_uid', $qb->createNamedParameter($ownerUid)))
            ->andWhere($qb->expr()->eq('normalized_name', $qb->createNamedParameter($normalizedName)))
            ->executeQuery()->fetchAssociative();
        return $row === false ? null : $row;
    }

    public function insertTag(array $tag): void {
        // CZ-FUNKCE: Provádí dílčí operaci `insertTag` v rámci Home Wiki. Vstup: `$tag`. Výstup: bez návratové hodnoty.
        $qb = $this->db->getQueryBuilder();
        $qb->insert(self::TABLE_TAGS)->values([
            'id' => $qb->createNamedParameter((string)$tag['id']),
            'owner_uid' => $qb->createNamedParameter((string)$tag['owner_uid']),
            'name' => $qb->createNamedParameter((string)$tag['name']),
            'normalized_name' => $qb->createNamedParameter((string)$tag['normalized_name']),
            'created_at' => $qb->createNamedParameter((int)$tag['created_at'], IQueryBuilder::PARAM_INT),
            'updated_at' => $qb->createNamedParameter((int)$tag['updated_at'], IQueryBuilder::PARAM_INT),
        ])->executeStatement();
    }

    public function renameTag(string $id, string $ownerUid, string $name, string $normalizedName): bool {
        // CZ-FUNKCE: Přejmenuje požadovaný objekt. Vstup: `$id`, `$ownerUid`, `$name`, `$normalizedName`. Výstup: hodnota typu `bool`.
        $qb = $this->db->getQueryBuilder();
        return $qb->update(self::TABLE_TAGS)
            ->set('name', $qb->createNamedParameter($name))
            ->set('normalized_name', $qb->createNamedParameter($normalizedName))
            ->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->andWhere($qb->expr()->eq('owner_uid', $qb->createNamedParameter($ownerUid)))
            ->executeStatement() === 1;
    }

    public function deleteTag(string $id, string $ownerUid): bool {
        // CZ-FUNKCE: Smaže požadovaná data. Vstup: `$id`, `$ownerUid`. Výstup: hodnota typu `bool`.
        if ($this->getTagOwned($id, $ownerUid) === null) {
            return false;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE_PAGE_TAGS)
            ->where($qb->expr()->eq('tag_id', $qb->createNamedParameter($id)))
            ->executeStatement();
        $qb = $this->db->getQueryBuilder();
        return $qb->delete(self::TABLE_TAGS)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->andWhere($qb->expr()->eq('owner_uid', $qb->createNamedParameter($ownerUid)))
            ->executeStatement() === 1;
    }

    public function deleteAllTagsOwned(string $ownerUid): void {
        // CZ-FUNKCE: Smaže požadovaná data. Vstup: `$ownerUid`. Výstup: bez návratové hodnoty.
        foreach ($this->listTagsOwned($ownerUid) as $tag) {
            $this->deleteTag((string)$tag['id'], $ownerUid);
        }
    }

    /** @return list<array{id:string,owner_uid:string,name:string}> */
    public function getTagsForPage(string $pageId): array {
        // CZ-FUNKCE: Načte a vrátí požadovaná data. Vstup: `$pageId`. Výstup: hodnota typu `array`.
        $qb = $this->db->getQueryBuilder();
        $tagIds = array_values(array_map('strval', $qb->select('tag_id')
            ->from(self::TABLE_PAGE_TAGS)
            ->where($qb->expr()->eq('page_id', $qb->createNamedParameter($pageId)))
            ->executeQuery()->fetchFirstColumn()));
        $result = [];
        foreach ($tagIds as $tagId) {
            $tagQb = $this->db->getQueryBuilder();
            $row = $tagQb->select('id', 'owner_uid', 'name', 'normalized_name')
                ->from(self::TABLE_TAGS)
                ->where($tagQb->expr()->eq('id', $tagQb->createNamedParameter($tagId)))
                ->executeQuery()->fetchAssociative();
            if ($row !== false) {
                $result[] = [
                    'id' => (string)$row['id'],
                    'owner_uid' => (string)$row['owner_uid'],
                    'name' => (string)$row['name'],
                    '_normalized_name' => (string)$row['normalized_name'],
                ];
            }
        }
        usort($result, static fn(array $a, array $b): int => strcmp((string)$a['_normalized_name'], (string)$b['_normalized_name']));
        return array_map(static function (array $row): array {
            unset($row['_normalized_name']);
            return $row;
        }, $result);
    }

    public function addTagToPage(string $pageId, string $tagId): bool {
        // CZ-FUNKCE: Přidá požadovaný záznam nebo vazbu. Vstup: `$pageId`, `$tagId`. Výstup: hodnota typu `bool`.
        $qb = $this->db->getQueryBuilder();
        $exists = $qb->select('id')
            ->from(self::TABLE_PAGE_TAGS)
            ->where($qb->expr()->eq('page_id', $qb->createNamedParameter($pageId)))
            ->andWhere($qb->expr()->eq('tag_id', $qb->createNamedParameter($tagId)))
            ->executeQuery()->fetchOne();
        if ($exists !== false) {
            return false;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->insert(self::TABLE_PAGE_TAGS)->values([
            'page_id' => $qb->createNamedParameter($pageId),
            'tag_id' => $qb->createNamedParameter($tagId),
            'created_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
        ])->executeStatement();
        return true;
    }

    public function removeTagFromPage(string $pageId, string $tagId): bool {
        // CZ-FUNKCE: Odstraní požadovaná data nebo vazbu. Vstup: `$pageId`, `$tagId`. Výstup: hodnota typu `bool`.
        $qb = $this->db->getQueryBuilder();
        return $qb->delete(self::TABLE_PAGE_TAGS)
            ->where($qb->expr()->eq('page_id', $qb->createNamedParameter($pageId)))
            ->andWhere($qb->expr()->eq('tag_id', $qb->createNamedParameter($tagId)))
            ->executeStatement() > 0;
    }

    private function addAlias(string $pageId, string $ownerUid, string $alias): void {
        // CZ-FUNKCE: Přidá požadovaný záznam nebo vazbu. Vstup: `$pageId`, `$ownerUid`, `$alias`. Výstup: bez návratové hodnoty.
        $cleanAlias = trim($alias, ' /');
        if ($cleanAlias === '') {
            return;
        }

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->insert(self::TABLE_ALIASES)->values([
                'page_id' => $qb->createNamedParameter($pageId),
                'owner_uid' => $qb->createNamedParameter($ownerUid),
                'alias' => $qb->createNamedParameter($cleanAlias),
            ])->executeStatement();
        } catch (\Throwable) {
            // An alias already owned by the same user is intentionally kept stable.
        }
    }

    private function resolveAlias(string $alias, string $ownerUid): ?string {
        // CZ-FUNKCE: Vyřeší a vrátí odpovídající hodnotu. Vstup: `$alias`, `$ownerUid`. Výstup: hodnota typu `?string`.
        foreach ($this->aliasCandidates($alias) as $cleanAlias) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('page_id')
                ->from(self::TABLE_ALIASES)
                ->where($qb->expr()->eq('owner_uid', $qb->createNamedParameter($ownerUid)))
                ->andWhere($qb->expr()->eq('alias', $qb->createNamedParameter($cleanAlias)));
            $sameOwner = $qb->executeQuery()->fetchOne();
            if ($sameOwner !== false) {
                return (string)$sameOwner;
            }

            // Cross-owner links are accepted only when the alias resolves uniquely.
            $qb = $this->db->getQueryBuilder();
            $qb->select('page_id')
                ->from(self::TABLE_ALIASES)
                ->where($qb->expr()->eq('alias', $qb->createNamedParameter($cleanAlias)))
                ->setMaxResults(2);
            $matches = $qb->executeQuery()->fetchFirstColumn();
            if (count($matches) === 1) {
                return (string)$matches[0];
            }
        }

        return null;
    }

    private function aliasCandidates(string $alias): array {
        // CZ-FUNKCE: Provádí dílčí operaci `aliasCandidates` v rámci Home Wiki. Vstup: `$alias`. Výstup: hodnota typu `array`.
        $clean = trim($alias, ' /');
        if ($clean === '') {
            return [];
        }
        $candidates = [$clean];
        $slug = $clean;
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $slug);
        if (is_string($ascii) && $ascii !== '') {
            $slug = $ascii;
        }
        $slug = strtolower($slug);
        $slug = preg_replace('~[^a-z0-9/_-]+~', '-', $slug) ?? $slug;
        $slug = preg_replace('~-+~', '-', $slug) ?? $slug;
        $slug = trim($slug, '/-');
        if ($slug !== '' && !in_array($slug, $candidates, true)) {
            $candidates[] = mb_substr($slug, 0, 255);
        }
        return $candidates;
    }

    private function insertLink(string $sourceId, ?string $targetId, string $raw, string $type): void {
        // CZ-FUNKCE: Provádí dílčí operaci `insertLink` v rámci Home Wiki. Vstup: `$sourceId`, `$targetId`, `$raw`, `$type`. Výstup: bez návratové hodnoty.
        $qb = $this->db->getQueryBuilder();
        $qb->insert(self::TABLE_LINKS)->values([
            'source_page_id' => $qb->createNamedParameter($sourceId),
            'target_page_id' => $qb->createNamedParameter($targetId),
            'target_raw' => $qb->createNamedParameter(mb_substr($raw, 0, 1024)),
            'link_type' => $qb->createNamedParameter($type),
        ])->executeStatement();
    }
}
