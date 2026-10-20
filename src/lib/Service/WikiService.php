<?php

declare(strict_types=1);

namespace OCA\HcHomeWiki\Service;

use OCA\HcHomeWiki\AppInfo\Application;
use OCP\Config\IUserConfig;
use OCP\Files\GenericFileException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

final class WikiService {
    private const MAX_CONTENT_BYTES = 5 * 1024 * 1024;
    private const RECENT_CONFIG_KEY = 'recent_pages_json';
    private const RECENT_STORAGE_LIMIT = 50;
    private const RECENT_DISPLAY_LIMIT = 6;
    private const FREQUENT_DISPLAY_LIMIT = 6;
    private const FREQUENT_HALF_LIFE_SECONDS = 14 * 24 * 60 * 60;
    private const SIDEBAR_SECTIONS_CONFIG_KEY = 'sidebar_sections_json';
    private const TRASH_RETENTION_CONFIG_KEY = 'trash_retention_days';
    private const TRASH_RETENTION_DAYS = [0, 7, 14, 30, 90];
    private const MENU_DENSITY_CONFIG_KEY = 'menu_density';
    private const MENU_DENSITIES = ['compact', 'comfortable'];
    private const PAGE_HEADER_MODE_CONFIG_KEY = 'page_header_mode';
    private const PAGE_HEADER_MODES = ['scroll', 'sticky'];
    private const MAX_TAG_NAME_LENGTH = 64;
    private const MAX_IMPORT_ARCHIVE_BYTES = 200 * 1024 * 1024;
    private const MAX_IMPORT_PAGES = 2000;
    private const MAX_IMPORT_MANIFEST_BYTES = 10 * 1024 * 1024;
    private const MAX_IMPORT_ENTRIES = 25000;
    private const MAX_IMPORT_TOTAL_CONTENT_BYTES = 100 * 1024 * 1024;
    private const MAX_IMPORT_TOTAL_MEDIA_BYTES = 250 * 1024 * 1024;
    private const MAX_IMPORT_MEDIA_FILES = 5000;
    private const MAX_IMPORT_TOTAL_ATTACHMENT_BYTES = 500 * 1024 * 1024;
    private const MAX_IMPORT_ATTACHMENT_FILES = 5000;

    public function __construct(
        private WikiRepository $repository,
        private WikiStorageService $storage,
        private WikiVersionService $versions,
        private IUserManager $userManager,
        private IGroupManager $groupManager,
        private IUserConfig $userConfig,
        private IAppConfig $appConfig,
        private IL10N $l10n,
        private LoggerInterface $logger,
    ) {
        // CZ-FUNKCE: Inicializuje objekt a jeho závislosti. Vstup: `$repository`, `$storage`, `$versions`, `$userManager`, `$groupManager`, `$userConfig`, `$appConfig`, `$l10n`, `$logger`. Výstup: bez samostatné návratové hodnoty; výsledkem je inicializovaný objekt.
    }

    public function listPages(string $uid): array {
        // CZ-FUNKCE: Vrací seznam dat pro danou operaci. Vstup: `$uid`. Výstup: hodnota typu `array`.
        return $this->repository->listVisible($uid);
    }

    public function getPage(string $uid, string $id): ?array {
        // CZ-FUNKCE: Načte a vrátí požadovaná data. Vstup: `$uid`, `$id`. Výstup: hodnota typu `?array`.
        $this->assertUuid($id);
        $page = $this->repository->get($id, $uid);
        if ($page === null) {
            return null;
        }

        $ownerUid = (string)$page['owner_uid'];
        $filePath = (string)$page['file_path'];

        // Reconcile old uncommitted trash residue before the first file read.
        // This covers both variants left by older failed deletes: the live file
        // may be missing with its only copy in .trash, or both physical copies
        // may exist while no logical trash record was ever committed.
        $trash = $this->repository->getTrashOwned($id, $ownerUid);
        if ($trash === null && $this->storage->reconcileUncommittedTrashResidue($ownerUid, $filePath, $id)) {
            $this->logger->warning('Home Wiki reconciled stale trash residue while loading a live page.', [
                'pageId' => $id,
                'ownerUid' => $ownerUid,
            ]);
        }

        try {
            $page['content'] = $this->storage->readPage($ownerUid, $filePath);
        } catch (GenericFileException $error) {
            // Keep one defensive retry for a race between the preflight check
            // and the actual file read.
            if ($trash !== null || !$this->storage->reconcileUncommittedTrashResidue($ownerUid, $filePath, $id)) {
                throw $error;
            }
            $this->logger->warning('Home Wiki reconciled stale trash residue after read retry.', [
                'pageId' => $id,
                'ownerUid' => $ownerUid,
            ]);
            $page['content'] = $this->storage->readPage($ownerUid, $filePath);
        }
        $page['backlinks'] = $this->repository->backlinks($id, $uid);
        return $page;
    }

    /** Lightweight state endpoint for open-page revision watchers. */
    public function getPageState(string $uid, string $id): ?array {
        // CZ-FUNKCE: Načte a vrátí požadovaná data. Vstup: `$uid`, `$id`. Výstup: hodnota typu `?array`.
        $this->assertUuid($id);
        $page = $this->repository->get($id, $uid);
        if ($page === null) {
            return null;
        }

        return [
            'id' => (string)$page['id'],
            'revision' => max(1, (int)($page['revision'] ?? 1)),
            'updated_at' => (int)($page['updated_at'] ?? 0),
        ];
    }

    /**
     * Return recent and frequently used pages. Recent is strict MRU order;
     * frequent uses a recency-decayed score so a page that was popular months
     * ago does not stay at the top forever. The visit log is kept per user.
     *
     * @return array{pages:list<array<string,mixed>>,frequent:list<array<string,mixed>>}
     */
    public function listRecentOverview(string $uid): array {
        // CZ-FUNKCE: Vrací seznam dat pro danou operaci. Vstup: `$uid`. Výstup: hodnota typu `array`.
        [$entries, $pagesById] = $this->cleanRecentEntries($uid);
        $recent = [];
        foreach ($entries as $entry) {
            $id = (string)$entry['id'];
            if (!isset($pagesById[$id])) {
                continue;
            }
            $page = $pagesById[$id];
            $page['recent_visited_at'] = (int)$entry['visitedAt'];
            $recent[] = $page;
            if (count($recent) >= self::RECENT_DISPLAY_LIMIT) {
                break;
            }
        }

        $now = time();
        $frequent = [];
        foreach ($entries as $entry) {
            $id = (string)$entry['id'];
            if (!isset($pagesById[$id]) || (int)$entry['visitCount'] < 2) {
                continue;
            }
            $page = $pagesById[$id];
            $page['recent_visited_at'] = (int)$entry['visitedAt'];
            $page['visit_count'] = (int)$entry['visitCount'];
            $page['frequent_score'] = $this->effectiveFrequentScore($entry, $now);
            $frequent[] = $page;
        }
        usort($frequent, static fn(array $left, array $right): int =>
            ((float)$right['frequent_score'] <=> (float)$left['frequent_score'])
            ?: ((int)$right['recent_visited_at'] <=> (int)$left['recent_visited_at'])
        );

        return [
            'pages' => $recent,
            'frequent' => array_slice($frequent, 0, self::FREQUENT_DISPLAY_LIMIT),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function listRecentPages(string $uid): array {
        // CZ-FUNKCE: Vrací seznam dat pro danou operaci. Vstup: `$uid`. Výstup: hodnota typu `array`.
        return $this->listRecentOverview($uid)['pages'];
    }

    /** @return array{pages:list<array<string,mixed>>,frequent:list<array<string,mixed>>} */
    public function recordPageVisit(string $uid, string $id): array {
        // CZ-FUNKCE: Zapíše událost nebo statistický údaj. Vstup: `$uid`, `$id`. Výstup: hodnota typu `array`.
        $this->assertUuid($id);
        if ($this->repository->get($id, $uid) === null) {
            throw new \RuntimeException($this->l10n->t('Page not found'), 404);
        }

        $now = time();
        $entries = $this->loadRecentEntries($uid);
        $previous = null;
        $remaining = [];
        foreach ($entries as $entry) {
            if ((string)$entry['id'] === $id) {
                $previous = $entry;
                continue;
            }
            $remaining[] = $entry;
        }

        $score = 1.0;
        $visitCount = 1;
        if (is_array($previous)) {
            $score = $this->effectiveFrequentScore($previous, $now) + 1.0;
            $visitCount = max(1, (int)$previous['visitCount']) + 1;
        }

        array_unshift($remaining, [
            'id' => $id,
            'visitedAt' => $now,
            'visitCount' => $visitCount,
            'score' => $score,
            'scoreUpdatedAt' => $now,
        ]);
        $this->storeRecentEntries($uid, array_slice($remaining, 0, self::RECENT_STORAGE_LIMIT));
        return $this->listRecentOverview($uid);
    }

    /** @return list<array{id:string,visitedAt:int,visitCount:int,score:float,scoreUpdatedAt:int}> */
    private function loadRecentEntries(string $uid): array {
        // CZ-FUNKCE: Načte požadovaná data. Vstup: `$uid`. Výstup: hodnota typu `array`.
        $raw = trim($this->userConfig->getValueString($uid, Application::APP_ID, self::RECENT_CONFIG_KEY, ''));
        if ($raw === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!is_array($decoded)) {
            return [];
        }

        $result = [];
        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $id = trim((string)($entry['id'] ?? ''));
            $visitedAt = (int)($entry['visitedAt'] ?? 0);
            if ($id === '' || $visitedAt < 1) {
                continue;
            }
            $visitCount = max(1, (int)($entry['visitCount'] ?? 1));
            $score = max(0.0, (float)($entry['score'] ?? 1.0));
            $scoreUpdatedAt = max(1, (int)($entry['scoreUpdatedAt'] ?? $visitedAt));
            $result[] = [
                'id' => $id,
                'visitedAt' => $visitedAt,
                'visitCount' => $visitCount,
                'score' => $score,
                'scoreUpdatedAt' => $scoreUpdatedAt,
            ];
            if (count($result) >= self::RECENT_STORAGE_LIMIT) {
                break;
            }
        }
        return $result;
    }

    /**
     * @return array{0:list<array{id:string,visitedAt:int,visitCount:int,score:float,scoreUpdatedAt:int}>,1:array<string,array<string,mixed>>}
     */
    private function cleanRecentEntries(string $uid): array {
        // CZ-FUNKCE: Provádí dílčí operaci `cleanRecentEntries` v rámci Home Wiki. Vstup: `$uid`. Výstup: hodnota typu `array`.
        $entries = $this->loadRecentEntries($uid);
        $clean = [];
        $pagesById = [];
        foreach ($entries as $entry) {
            $id = (string)$entry['id'];
            try {
                $this->assertUuid($id);
            } catch (\InvalidArgumentException) {
                continue;
            }
            $page = $this->repository->get($id, $uid);
            if ($page === null) {
                continue;
            }
            $clean[] = $entry;
            $pagesById[$id] = $page;
        }
        if ($clean !== $entries) {
            $this->storeRecentEntries($uid, $clean);
        }
        return [$clean, $pagesById];
    }

    /** @param array{id:string,visitedAt:int,visitCount:int,score:float,scoreUpdatedAt:int} $entry */
    private function effectiveFrequentScore(array $entry, int $at): float {
        // CZ-FUNKCE: Provádí dílčí operaci `effectiveFrequentScore` v rámci Home Wiki. Vstup: `$entry`, `$at`. Výstup: hodnota typu `float`.
        $score = max(0.0, (float)$entry['score']);
        $updatedAt = max(1, (int)$entry['scoreUpdatedAt']);
        $age = max(0, $at - $updatedAt);
        return $score * pow(0.5, $age / self::FREQUENT_HALF_LIFE_SECONDS);
    }

    /** @param list<array{id:string,visitedAt:int,visitCount:int,score:float,scoreUpdatedAt:int}> $entries */
    private function storeRecentEntries(string $uid, array $entries): void {
        // CZ-FUNKCE: Uloží data pro další použití. Vstup: `$uid`, `$entries`. Výstup: bez návratové hodnoty.
        if ($entries === []) {
            $this->userConfig->deleteUserConfig($uid, Application::APP_ID, self::RECENT_CONFIG_KEY);
            return;
        }
        $this->userConfig->setValueString(
            $uid,
            Application::APP_ID,
            self::RECENT_CONFIG_KEY,
            json_encode($entries, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }

    public function listPageVersions(string $uid, string $id): array {
        // CZ-FUNKCE: Vrací seznam dat pro danou operaci. Vstup: `$uid`, `$id`. Výstup: hodnota typu `array`.
        $page = $this->editablePageForHistory($uid, $id);
        return $this->versions->listForPage($page);
    }

    public function getPageVersion(string $uid, string $id, string $versionId): array {
        // CZ-FUNKCE: Načte a vrátí požadovaná data. Vstup: `$uid`, `$id`, `$versionId`. Výstup: hodnota typu `array`.
        $page = $this->editablePageForHistory($uid, $id);
        try {
            $version = $this->versions->readForPage($page, $versionId);
        } catch (\RuntimeException $exception) {
            if ($exception->getCode() === 503) {
                throw new \RuntimeException($this->l10n->t('Version history is unavailable'), 503, $exception);
            }
            throw $exception;
        }
        if ($version === null) {
            throw new \RuntimeException($this->l10n->t('Page version not found'), 404);
        }
        return $version;
    }

    public function restorePageVersion(string $uid, string $id, string $versionId, int $expectedRevision): array {
        // CZ-FUNKCE: Obnoví dříve odstraněná nebo verzovaná data. Vstup: `$uid`, `$id`, `$versionId`, `$expectedRevision`. Výstup: hodnota typu `array`.
        if ($expectedRevision < 1) {
            throw new \InvalidArgumentException($this->l10n->t('Invalid page revision'));
        }
        $version = $this->getPageVersion($uid, $id, $versionId);
        $restored = $this->updatePage($uid, $id, [
            'expectedRevision' => $expectedRevision,
            'content' => (string)$version['content'],
        ]);
        if ($restored === null) {
            throw new \RuntimeException($this->l10n->t('Page not found'), 404);
        }
        return $restored;
    }

    private function editablePageForHistory(string $uid, string $id): array {
        // CZ-FUNKCE: Provádí dílčí operaci `editablePageForHistory` v rámci Home Wiki. Vstup: `$uid`, `$id`. Výstup: hodnota typu `array`.
        $this->assertUuid($id);
        $page = $this->repository->get($id, $uid);
        if ($page === null) {
            throw new \RuntimeException($this->l10n->t('Page not found'), 404);
        }
        // Historical content can predate the current sharing policy. Expose it
        // only to users who can edit the current page, never to read-only shares.
        if (!(bool)($page['permissions']['edit'] ?? false)) {
            throw new \RuntimeException($this->l10n->t('Edit permission required'), 403);
        }
        return $page;
    }

    public function createPage(string $uid, array $input): array {
        return $this->storage->withOwnerMutationLock($uid, fn(): array => $this->createPageLocked($uid, $input));
    }

    private function createPageLocked(string $uid, array $input): array {
        // CZ-FUNKCE: Vytvoří nový záznam nebo objekt. Vstup: `$uid`, `$input`. Výstup: hodnota typu `array`.
        $title = $this->normalizeTitle($input['title'] ?? $this->l10n->t('New page'));
        $id = $this->repository->newId();
        $slug = $this->uniqueSlug($uid, $this->normalizeSlug($input['slug'] ?? $title));
        $parentId = $this->normalizeParentId($uid, $input['parentId'] ?? null);
        $visibility = $this->normalizeVisibility($input['visibility'] ?? 'private');
        $allPermissions = $this->normalizeAllPermissions($input['allPermissions'] ?? [], $visibility);
        $acl = $this->normalizeAcl($input['acl'] ?? []);
        $content = $this->normalizeContent($input['content'] ?? ('# ' . $title . "\n\n"));
        $path = $this->storage->pagePath($id, $slug);
        $now = time();

        $page = [
            'id' => $id,
            'owner_uid' => $uid,
            'parent_id' => $parentId,
            'title' => $title,
            'slug' => $slug,
            'file_path' => $path,
            'visibility' => $visibility,
            'pinned' => (bool)($input['pinned'] ?? false),
            'sort_order' => $this->normalizeSortOrder($input['sortOrder'] ?? 0),
            'created_at' => $now,
            'updated_at' => $now,
            'revision' => 1,
            'all_can_edit' => $allPermissions['edit'],
            'all_can_delete' => $allPermissions['delete'],
            'all_can_share' => $allPermissions['share'],
        ];

        $inserted = false;
        $stage = 'file';
        try {
            $this->storage->writePage($uid, $path, $content, $this->backupMetadata($page, $visibility !== 'private' ? $acl : [], $content));
            $stage = 'database';
            $this->repository->transactional(function () use ($page, $id, $visibility, $acl, $uid, $content): void {
                $this->repository->insert($page);
                $this->repository->replaceAcl($id, $visibility !== 'private' ? $acl : []);
                $this->repository->rebuildLinks($id, $uid, $content);
            });
            $inserted = true;
            $stage = 'readback';
            $created = $this->getPage($uid, $id);
            if ($created === null) {
                throw new \RuntimeException('Created page could not be loaded');
            }
            return $created;
        } catch (\Throwable $exception) {
            if (!$inserted) {
                try {
                    $this->storage->deletePage($uid, $path);
                } catch (\Throwable $cleanupError) {
                    $this->logger->warning('Home Wiki could not clean up a failed page file.', [
                        'exception' => $cleanupError,
                        'pageId' => $id,
                    ]);
                }
            }
            $this->logger->error('Home Wiki page creation failed.', [
                'exception' => $exception,
                'pageId' => $id,
                'stage' => $stage,
                'databaseRowCreated' => $inserted,
            ]);
            throw new \RuntimeException($this->l10n->t('Could not create page'), 0, $exception);
        }
    }

    public function updatePage(string $uid, string $id, array $input): ?array {
        // CZ-FUNKCE: Aktualizuje existující data. Vstup: `$uid`, `$id`, `$input`. Výstup: hodnota typu `?array`.
        $this->assertUuid($id);
        $page = $this->repository->get($id, $uid);
        if ($page === null) {
            return null;
        }

        $canEdit = (bool)$page['permissions']['edit'];
        $canShare = (bool)$page['permissions']['share'];
        if (!$canEdit && !$canShare) {
            throw new \RuntimeException($this->l10n->t('Permission denied'), 403);
        }

        if (!array_key_exists('expectedRevision', $input)) {
            throw new \InvalidArgumentException($this->l10n->t('Missing page revision'));
        }
        $expectedRevision = (int)$input['expectedRevision'];
        if ($expectedRevision < 1) {
            throw new \InvalidArgumentException($this->l10n->t('Invalid page revision'));
        }

        $title = $canEdit ? $this->normalizeTitle($input['title'] ?? $page['title']) : (string)$page['title'];
        $slug = $canEdit
            ? $this->uniqueSlug((string)$page['owner_uid'], $this->normalizeSlug($input['slug'] ?? $page['slug']), $id)
            : (string)$page['slug'];
        $parentId = $canEdit
            ? $this->normalizeParentId((string)$page['owner_uid'], array_key_exists('parentId', $input) ? $input['parentId'] : $page['parent_id'], $id)
            : $page['parent_id'];
        $visibility = $canShare
            ? $this->normalizeVisibility($input['visibility'] ?? $page['visibility'])
            : (string)$page['visibility'];
        $currentAllPermissions = [
            'edit' => (bool)($page['all_can_edit'] ?? false),
            'delete' => (bool)($page['all_can_delete'] ?? false),
            'share' => (bool)($page['all_can_share'] ?? false),
        ];
        $allPermissions = $canShare
            ? $this->normalizeAllPermissions($input['allPermissions'] ?? $currentAllPermissions, $visibility)
            : $this->normalizeAllPermissions($currentAllPermissions, $visibility);
        $acl = $canShare && array_key_exists('acl', $input)
            ? $this->normalizeAcl($input['acl'])
            : null;
        $content = $canEdit && array_key_exists('content', $input)
            ? $this->normalizeContent($input['content'])
            : $this->storage->readPage((string)$page['owner_uid'], (string)$page['file_path']);
        $newPath = $this->storage->pagePath($id, $slug);
        $nextRevision = $expectedRevision + 1;

        $metadata = [
            'owner_uid' => (string)$page['owner_uid'],
            'parent_id' => $parentId,
            'title' => $title,
            'slug' => $slug,
            'file_path' => $newPath,
            'visibility' => $visibility,
            'pinned' => $canEdit ? (bool)($input['pinned'] ?? $page['pinned']) : (bool)$page['pinned'],
            'sort_order' => $canEdit ? $this->normalizeSortOrder($input['sortOrder'] ?? $page['sort_order']) : (int)$page['sort_order'],
            'created_at' => (int)$page['created_at'],
            'updated_at' => time(),
            'revision' => $nextRevision,
            'all_can_edit' => $allPermissions['edit'],
            'all_can_delete' => $allPermissions['delete'],
            'all_can_share' => $allPermissions['share'],
        ];

        $oldPath = (string)$page['file_path'];
        $oldRaw = $this->storage->readRawPage((string)$page['owner_uid'], $oldPath);
        $renameAttempted = false;
        $writeAttempted = false;
        $nextAcl = $visibility !== 'private' ? ($acl ?? $this->repository->getAcl($id)) : [];

        try {
            // CAS runs first while the transaction is open. A competing writer
            // blocks here and receives 409 before it can touch the Markdown file.
            $this->repository->transactional(function () use (
                $id, $expectedRevision, $metadata, $canShare, $acl, $visibility,
                $canEdit, $page, $content, $oldPath, $newPath,
                &$renameAttempted, &$writeAttempted, $nextAcl
            ): void {
                if (!$this->repository->updateCompareAndSwap($id, $expectedRevision, $metadata)) {
                    throw new \RuntimeException($this->l10n->t('This page was changed by another user or browser window.'), 409);
                }

                if ($canEdit && $newPath !== $oldPath) {
                    // Mark the rollback requirement before the Files operation.
                    // Nextcloud event listeners run inside move()/putContent() and may
                    // throw after the physical mutation has already happened.
                    $renameAttempted = true;
                    $this->storage->renamePage((string)$page['owner_uid'], $oldPath, $newPath);
                }
                $writeAttempted = true;
                $this->storage->writePage(
                    (string)$page['owner_uid'],
                    $newPath,
                    $content,
                    $this->backupMetadata(['id' => $id] + $metadata, $nextAcl, $content),
                );

                if ($canShare && $acl !== null) {
                    $this->repository->replaceAcl($id, $visibility !== 'private' ? $acl : []);
                }
                if ($canEdit) {
                    $this->repository->rebuildLinks($id, (string)$page['owner_uid'], $content);
                }
            });
        } catch (\Throwable $exception) {
            if (($renameAttempted || $writeAttempted) && $exception->getCode() !== 409) {
                try {
                    if ($renameAttempted) {
                        $this->storage->renamePage((string)$page['owner_uid'], $newPath, $oldPath);
                    }
                    $this->storage->writePage((string)$page['owner_uid'], $oldPath, $oldRaw);
                } catch (\Throwable $rollbackError) {
                    $this->logger->critical('Home Wiki file rollback failed after update error.', [
                        'exception' => $rollbackError,
                        'pageId' => $id,
                    ]);
                }
            }
            if ($exception instanceof \OCP\Lock\LockedException || $exception->getCode() === 409) {
                throw $exception;
            }
            $this->logger->error('Home Wiki page update failed.', [
                'exception' => $exception,
                'pageId' => $id,
            ]);
            throw new \RuntimeException($this->l10n->t('Could not save page'), 0, $exception);
        }

        $result = $this->getPage($uid, $id);
        if ($result !== null && $canShare && !empty($input['applyToDescendants'])) {
            $result['sharePropagation'] = $this->propagateSharingToDescendants(
                $uid,
                $id,
                (string)$page['owner_uid'],
                $visibility,
                $allPermissions,
                $nextAcl,
            );
        }

        return $result;
    }

    /** @return array{updated:int,skipped:int,total:int} */
    private function propagateSharingToDescendants(
        string $uid,
        string $rootId,
        string $ownerUid,
        string $visibility,
        array $allPermissions,
        array $acl,
    ): array {
        // CZ-FUNKCE: Provádí dílčí operaci `propagateSharingToDescendants` v rámci Home Wiki. Vstup: `$uid`, `$rootId`, `$ownerUid`, `$visibility`, `$allPermissions`, `$acl`. Výstup: hodnota typu `array`.
        $ids = $this->repository->descendantIds($ownerUid, $rootId);
        $updated = 0;
        $skipped = 0;

        foreach ($ids as $childId) {
            $done = false;
            for ($attempt = 0; $attempt < 2 && !$done; $attempt++) {
                $child = $this->repository->get($childId, $uid);
                if ($child === null || !(bool)($child['permissions']['share'] ?? false)) {
                    $skipped++;
                    break;
                }

                try {
                    $this->updatePage($uid, $childId, [
                        'expectedRevision' => (int)$child['revision'],
                        'visibility' => $visibility,
                        'allPermissions' => $allPermissions,
                        'acl' => $visibility === 'private' ? [] : $acl,
                    ]);
                    $updated++;
                    $done = true;
                } catch (\RuntimeException $exception) {
                    if ($exception->getCode() === 409 && $attempt === 0) {
                        continue;
                    }
                    $skipped++;
                    $this->logger->warning('Home Wiki could not propagate sharing to a descendant.', [
                        'exception' => $exception,
                        'rootPageId' => $rootId,
                        'pageId' => $childId,
                    ]);
                    break;
                }
            }
        }

        return ['updated' => $updated, 'skipped' => $skipped, 'total' => count($ids)];
    }

    /**
     * Move a page to the owner's Home Wiki trash. A shared editor with Delete
     * permission may move the page, but only the owner manages the trash.
     */
    public function deletePage(string $uid, string $id, int $expectedRevision, string $scope = 'single'): bool {
        // CZ-FUNKCE: Smaže požadovaná data. Vstup: `$uid`, `$id`, `$expectedRevision`, `$scope`. Výstup: hodnota typu `bool`.
        $this->assertUuid($id);
        $scope = strtolower(trim($scope));
        if (!in_array($scope, ['single', 'subtree'], true)) {
            throw new \InvalidArgumentException($this->l10n->t('Invalid delete scope'));
        }

        $rootPage = $this->repository->get($id, $uid);
        if ($rootPage === null) {
            throw new \RuntimeException($this->l10n->t('Page not found'), 404);
        }
        if (!(bool)($rootPage['permissions']['delete'] ?? false)) {
            throw new \RuntimeException($this->l10n->t('Permission denied'), 403);
        }
        if ($expectedRevision < 1) {
            throw new \InvalidArgumentException($this->l10n->t('Invalid page revision'));
        }

        $ownerUid = (string)$rootPage['owner_uid'];
        $targetIds = [$id];
        if ($scope === 'subtree') {
            $targetIds = array_merge($targetIds, $this->repository->descendantIds($ownerUid, $id));
        }

        $targets = [];
        foreach ($targetIds as $targetId) {
            $page = $targetId === $id ? $rootPage : $this->repository->get($targetId, $uid);
            if ($page === null || (string)$page['owner_uid'] !== $ownerUid) {
                throw new \RuntimeException($this->l10n->t('Page not found'), 404);
            }
            if (!(bool)($page['permissions']['delete'] ?? false)) {
                throw new \RuntimeException($this->l10n->t('Permission denied'), 403);
            }

            $rawPage = $this->repository->getRaw($targetId);
            if ($rawPage === null) {
                throw new \RuntimeException($this->l10n->t('Page not found'), 404);
            }

            // A live DB page must not have a committed trash record. If there
            // is no such record, clean up only uncommitted physical residue
            // left by a failed delete from an older build before moving again.
            if ($this->repository->getTrashOwned($targetId, $ownerUid) !== null) {
                throw new \RuntimeException($this->l10n->t('A deleted copy of this page already exists'), 409);
            }
            if ($this->storage->reconcileUncommittedTrashResidue($ownerUid, (string)$page['file_path'], $targetId)) {
                $this->logger->warning('Home Wiki reconciled stale trash residue before delete.', [
                    'pageId' => $targetId,
                    'ownerUid' => $ownerUid,
                ]);
            }

            $revision = $targetId === $id ? $expectedRevision : (int)($rawPage['revision'] ?? 0);
            if ($revision < 1) {
                throw new \InvalidArgumentException($this->l10n->t('Invalid page revision'));
            }
            $rawPage['revision'] = $revision + 1;
            $rawPage['updated_at'] = time();
            $rawPage['tags'] = array_values(array_map(
                static fn(array $tag): array => [
                    'id' => (string)($tag['id'] ?? ''),
                    'name' => (string)($tag['name'] ?? ''),
                ],
                $this->repository->getTagsForPage($targetId),
            ));

            $targets[] = [
                'id' => $targetId,
                'path' => (string)$page['file_path'],
                'revision' => $revision,
                'page' => $rawPage,
                'acl' => $this->repository->getAcl($targetId),
                'aliases' => $this->repository->getAliases($targetId),
            ];
        }

        /** @var list<array{id:string,path:string,trashPath:string}> $moved */
        $moved = [];
        try {
            $this->repository->transactional(function () use (
                $targets, $scope, $ownerUid, $id, $uid, &$moved
            ): void {
                // Claim every page before touching Files. This turns concurrent edits
                // anywhere in the selected subtree into an explicit conflict.
                foreach ($targets as $target) {
                    if (!$this->repository->claimRevision((string)$target['id'], (int)$target['revision'])) {
                        throw new \RuntimeException($this->l10n->t('This page was changed by another user or browser window.'), 409);
                    }
                }

                foreach ($targets as $target) {
                    $targetId = (string)$target['id'];
                    $trashPath = $this->storage->trashPage($ownerUid, (string)$target['path'], $targetId);
                    $moved[] = ['id' => $targetId, 'path' => (string)$target['path'], 'trashPath' => $trashPath];

                    $this->repository->insertTrash([
                        'id' => $targetId,
                        'owner_uid' => $ownerUid,
                        'title' => (string)$target['page']['title'],
                        'trash_path' => $trashPath,
                        'page_json' => json_encode($target['page'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                        'acl_json' => json_encode($target['acl'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                        'aliases_json' => json_encode($target['aliases'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                        'deleted_at' => time(),
                        'deleted_by' => $uid,
                    ]);
                }

                if ($scope === 'single') {
                    $root = $targets[0]['page'];
                    $parentId = isset($root['parent_id']) && is_string($root['parent_id']) && $root['parent_id'] !== ''
                        ? $root['parent_id'] : null;
                    $this->repository->reparentChildren($ownerUid, $id, $parentId);
                }

                foreach ($targets as $target) {
                    $this->repository->deleteForTrash((string)$target['id']);
                }
            });
        } catch (\Throwable $exception) {
            if ($moved !== []) {
                try {
                    // Files moves use Nextcloud's filecache on the same DB
                    // connection. After the application transaction rolls back,
                    // rescan before compensating physical moves so cache and disk
                    // describe the same locations again.
                    $this->storage->rescanWikiStorage($ownerUid);
                } catch (\Throwable $scanError) {
                    $this->logger->error('Home Wiki could not rescan storage before delete rollback.', [
                        'exception' => $scanError,
                        'ownerUid' => $ownerUid,
                    ]);
                }
            }
            foreach (array_reverse($moved) as $target) {
                try {
                    $this->storage->restoreTrashedPage(
                        $ownerUid,
                        (string)$target['trashPath'],
                        (string)$target['path'],
                        (string)$target['id'],
                    );
                } catch (\Throwable $rollbackError) {
                    $this->logger->critical('Home Wiki could not restore a page after tree trash transaction failure.', [
                        'exception' => $rollbackError,
                        'pageId' => (string)$target['id'],
                    ]);
                }
            }
            throw $exception;
        }

        return true;
    }

    /** @return list<array{id:string,title:string,deleted_at:int,deleted_by:string}> */
    public function listTrash(string $uid): array {
        // CZ-FUNKCE: Vrací seznam dat pro danou operaci. Vstup: `$uid`. Výstup: hodnota typu `array`.
        return $this->repository->listTrashOwned($uid);
    }

    /** Permanently delete every logical trash item owned by this user. */
    public function emptyTrash(string $uid): array {
        // CZ-FUNKCE: Provádí dílčí operaci `emptyTrash` v rámci Home Wiki. Vstup: `$uid`. Výstup: hodnota typu `array`.
        $deleted = 0;
        foreach ($this->repository->listTrashOwned($uid) as $trash) {
            $this->permanentlyDeleteTrash($uid, (string)$trash['id']);
            $deleted++;
        }
        return ['deleted' => $deleted];
    }

    /**
     * Daily housekeeping entry point. A user's retention policy is opt-in and
     * applies only to that user's own Home Wiki trash. One damaged item must
     * not block cleanup for other users or other trash rows.
     */
    public function cleanupExpiredTrash(): int {
        // CZ-FUNKCE: Provede úklid zastaralých nebo dočasných dat. Vstup: žádný přímý parametr. Výstup: hodnota typu `int`.
        $deleted = 0;
        $now = time();
        foreach ($this->userManager->getSeenUsers() as $user) {
            $uid = $user->getUID();
            $days = $this->trashRetentionDays($uid);
            if ($days <= 0) {
                continue;
            }
            $cutoff = $now - ($days * 24 * 60 * 60);
            foreach ($this->repository->listTrashOwned($uid) as $trash) {
                if ((int)$trash['deleted_at'] > $cutoff) {
                    continue;
                }
                try {
                    $this->permanentlyDeleteTrash($uid, (string)$trash['id']);
                    $deleted++;
                } catch (\Throwable $error) {
                    $this->logger->warning('Home Wiki automatic trash cleanup could not delete an item.', [
                        'exception' => $error,
                        'uid' => $uid,
                        'pageId' => (string)$trash['id'],
                    ]);
                }
            }
        }
        return $deleted;
    }

    private function trashRetentionDays(string $uid): int {
        // CZ-FUNKCE: Provádí dílčí operaci `trashRetentionDays` v rámci Home Wiki. Vstup: `$uid`. Výstup: hodnota typu `int`.
        $days = (int)$this->userConfig->getValueString(
            $uid,
            Application::APP_ID,
            self::TRASH_RETENTION_CONFIG_KEY,
            '0',
        );
        return in_array($days, self::TRASH_RETENTION_DAYS, true) ? $days : 0;
    }

    public function restoreTrash(string $uid, string $id): array {
        // CZ-FUNKCE: Obnoví dříve odstraněná nebo verzovaná data. Vstup: `$uid`, `$id`. Výstup: hodnota typu `array`.
        $this->assertUuid($id);
        $trash = $this->repository->getTrashOwned($id, $uid);
        if ($trash === null) {
            throw new \RuntimeException($this->l10n->t('Deleted page not found'), 404);
        }
        if ($this->repository->getRaw($id) !== null) {
            throw new \RuntimeException($this->l10n->t('A live page with this identifier already exists'), 409);
        }

        try {
            $page = json_decode((string)$trash['page_json'], true, 64, JSON_THROW_ON_ERROR);
            $acl = json_decode((string)$trash['acl_json'], true, 64, JSON_THROW_ON_ERROR);
            $aliases = json_decode((string)$trash['aliases_json'], true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException($this->l10n->t('Deleted page metadata is damaged'), 0, $exception);
        }
        if (!is_array($page) || !is_array($acl) || !is_array($aliases)) {
            throw new \RuntimeException($this->l10n->t('Deleted page metadata is damaged'));
        }
        $tagRefs = [];
        foreach (is_array($page['tags'] ?? null) ? $page['tags'] : [] as $tagItem) {
            // Store both tag ID and display name so a rename performed while the
            // page is in Trash is respected on restore. Legacy name-only
            // snapshots remain accepted.
            $tagId = is_array($tagItem) ? trim((string)($tagItem['id'] ?? '')) : '';
            $tagName = is_array($tagItem) ? ($tagItem['name'] ?? '') : $tagItem;
            try {
                $tagName = $this->normalizeTagName($tagName);
                if ($tagId !== '' && !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $tagId)) {
                    $tagId = '';
                }
                $tagRefs[] = ['id' => $tagId, 'name' => $tagName];
            } catch (\Throwable) { /* Old or damaged tag metadata is non-fatal. */ }
        }
        unset($page['tags']);

        $ownerUid = (string)$page['owner_uid'];
        if ($ownerUid !== $uid) {
            throw new \RuntimeException($this->l10n->t('Permission denied'), 403);
        }
        $originalPath = (string)$page['file_path'];
        $slug = $this->uniqueSlug($ownerUid, $this->normalizeSlug((string)$page['slug']), $id);
        $livePath = $this->storage->pagePath($id, $slug);
        $page['slug'] = $slug;
        $page['file_path'] = $livePath;
        $page['revision'] = max(1, (int)($page['revision'] ?? 1)) + 1;
        $page['updated_at'] = time();
        $trashPath = (string)$trash['trash_path'];
        $physicalRestored = false;

        try {
            $this->repository->transactional(function () use (
                $id, $ownerUid, $page, $acl, $aliases, $tagRefs, $trashPath, $livePath, &$physicalRestored
            ): void {
                $this->storage->restoreTrashedPage($ownerUid, $trashPath, $livePath, $id);
                $physicalRestored = true;
                $this->repository->insert($page);
                $this->repository->replaceAcl($id, $acl);
                $this->repository->restoreAliases($id, $ownerUid, array_values(array_filter($aliases, 'is_string')));
                foreach ($tagRefs as $tagRef) {
                    $tag = null;
                    $tagId = (string)($tagRef['id'] ?? '');
                    if ($tagId !== '') {
                        $tag = $this->repository->getTagOwned($tagId, $ownerUid);
                    }
                    if ($tag === null) {
                        $tag = $this->ensureOwnedTag($ownerUid, (string)$tagRef['name']);
                    }
                    $this->repository->addTagToPage($id, (string)$tag['id']);
                }
                $content = $this->storage->readPage($ownerUid, $livePath);
                $this->repository->rebuildLinks($id, $ownerUid, $content);
                $this->storage->writePage(
                    $ownerUid,
                    $livePath,
                    $content,
                    $this->backupMetadata(['id' => $id] + $page, $acl, $content),
                );
                $this->repository->deleteTrashRecord($id);
            });
        } catch (\Throwable $exception) {
            if ($physicalRestored) {
                try {
                    $this->storage->rescanWikiStorage($ownerUid);
                    $newTrashPath = $this->storage->trashPage($ownerUid, $livePath, $id);
                    if ($newTrashPath !== $trashPath) {
                        $this->storage->renamePage($ownerUid, $newTrashPath, $trashPath);
                    }
                } catch (\Throwable $rollbackError) {
                    $this->logger->critical('Home Wiki could not return a failed restore to trash.', [
                        'exception' => $rollbackError,
                        'pageId' => $id,
                    ]);
                }
            }
            throw $exception;
        }

        $restored = $this->getPage($uid, $id);
        if ($restored === null) {
            throw new \RuntimeException($this->l10n->t('Restored page could not be loaded'));
        }
        return $restored;
    }

    public function permanentlyDeleteTrash(string $uid, string $id): bool {
        // CZ-FUNKCE: Provádí dílčí operaci `permanentlyDeleteTrash` v rámci Home Wiki. Vstup: `$uid`, `$id`. Výstup: hodnota typu `bool`.
        $this->assertUuid($id);
        $trash = $this->repository->getTrashOwned($id, $uid);
        if ($trash === null) {
            throw new \RuntimeException($this->l10n->t('Deleted page not found'), 404);
        }
        try {
            $page = json_decode((string)$trash['page_json'], true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException($this->l10n->t('Deleted page metadata is damaged'), 0, $exception);
        }
        if (!is_array($page)) {
            throw new \RuntimeException($this->l10n->t('Deleted page metadata is damaged'));
        }

        // Physical deletion is idempotent. If the subsequent DB cleanup fails,
        // keeping the trash row makes a retry safe and visible instead of hiding it.
        $this->storage->deleteTrashedPage($uid, (string)$trash['trash_path'], $id);
        $this->repository->transactional(function () use ($id, $uid, $page): void {
            $this->repository->removeIncomingLinks($id);
            $parentId = isset($page['parent_id']) && is_string($page['parent_id']) && $page['parent_id'] !== ''
                ? $page['parent_id'] : null;
            $this->repository->reparentChildren($uid, $id, $parentId);
            $this->repository->deleteTrashRecord($id);
        });
        return true;
    }


    /** @return array{own:list<array<string,mixed>>,visible:list<array<string,mixed>>} */
    public function listTags(string $uid): array {
        // CZ-FUNKCE: Vrací seznam dat pro danou operaci. Vstup: `$uid`. Výstup: hodnota typu `array`.
        $own = array_map(static fn(array $tag): array => [
            'id' => (string)$tag['id'],
            'ownerUid' => (string)$tag['owner_uid'],
            'name' => (string)$tag['name'],
            'count' => (int)$tag['count'],
            'own' => true,
        ], $this->repository->listTagsOwned($uid));

        $visible = [];
        foreach ($this->repository->listVisible($uid) as $page) {
            foreach (is_array($page['tags'] ?? null) ? $page['tags'] : [] as $tag) {
                if (!is_array($tag)) continue;
                $id = (string)($tag['id'] ?? '');
                if ($id === '') continue;
                if (!isset($visible[$id])) {
                    $visible[$id] = [
                        'id' => $id,
                        'ownerUid' => (string)($tag['owner_uid'] ?? ''),
                        'name' => (string)($tag['name'] ?? ''),
                        'count' => 0,
                        'own' => (string)($tag['owner_uid'] ?? '') === $uid,
                    ];
                }
                $visible[$id]['count']++;
            }
        }
        $visible = array_values($visible);
        usort($visible, static fn(array $a, array $b): int => strnatcasecmp((string)$a['name'], (string)$b['name']));
        return ['own' => $own, 'visible' => $visible];
    }

    public function createTag(string $uid, array $input): array {
        // CZ-FUNKCE: Vytvoří nový záznam nebo objekt. Vstup: `$uid`, `$input`. Výstup: hodnota typu `array`.
        $name = $this->normalizeTagName($input['name'] ?? '');
        return $this->ensureOwnedTag($uid, $name);
    }

    public function renameTag(string $uid, string $tagId, array $input): array {
        // CZ-FUNKCE: Přejmenuje požadovaný objekt. Vstup: `$uid`, `$tagId`, `$input`. Výstup: hodnota typu `array`.
        $this->assertUuid($tagId);
        if ($this->repository->getTagOwned($tagId, $uid) === null) {
            throw new \RuntimeException($this->l10n->t('Tag not found'), 404);
        }
        $name = $this->normalizeTagName($input['name'] ?? '');
        $normalized = $this->normalizeTagKey($name);
        $duplicate = $this->repository->getTagByNormalizedName($uid, $normalized);
        if ($duplicate !== null && (string)$duplicate['id'] !== $tagId) {
            throw new \RuntimeException($this->l10n->t('A tag with this name already exists'), 409);
        }
        if (!$this->repository->renameTag($tagId, $uid, $name, $normalized)) {
            throw new \RuntimeException($this->l10n->t('Tag not found'), 404);
        }
        return $this->listTags($uid);
    }

    public function deleteTag(string $uid, string $tagId): array {
        // CZ-FUNKCE: Smaže požadovaná data. Vstup: `$uid`, `$tagId`. Výstup: hodnota typu `array`.
        $this->assertUuid($tagId);
        $deleted = false;
        $this->repository->transactional(function () use ($tagId, $uid, &$deleted): void {
            $deleted = $this->repository->deleteTag($tagId, $uid);
        });
        if (!$deleted) {
            throw new \RuntimeException($this->l10n->t('Tag not found'), 404);
        }
        return $this->listTags($uid);
    }

    public function assignTag(string $uid, string $pageId, array $input): array {
        // CZ-FUNKCE: Provádí dílčí operaci `assignTag` v rámci Home Wiki. Vstup: `$uid`, `$pageId`, `$input`. Výstup: hodnota typu `array`.
        $this->assertUuid($pageId);
        if ($this->repository->getOwned($pageId, $uid) === null) {
            throw new \RuntimeException($this->l10n->t('Only the page owner can change tags'), 403);
        }
        $tagId = trim((string)($input['tagId'] ?? ''));
        $this->assertUuid($tagId);
        if ($this->repository->getTagOwned($tagId, $uid) === null) {
            throw new \RuntimeException($this->l10n->t('Tag not found'), 404);
        }
        $scope = strtolower(trim((string)($input['scope'] ?? 'single')));
        if (!in_array($scope, ['single', 'subtree'], true)) {
            throw new \InvalidArgumentException($this->l10n->t('Invalid tag scope'));
        }
        $ids = [$pageId];
        if ($scope === 'subtree') $ids = array_merge($ids, $this->repository->descendantIds($uid, $pageId));
        $updated = 0;
        $this->repository->transactional(function () use ($ids, $tagId, &$updated): void {
            foreach ($ids as $id) if ($this->repository->addTagToPage((string)$id, $tagId)) $updated++;
        });
        return ['updated' => $updated, 'page' => $this->getPage($uid, $pageId), 'tags' => $this->listTags($uid)];
    }

    public function removeTag(string $uid, string $pageId, string $tagId, string $scope = 'single'): array {
        // CZ-FUNKCE: Odstraní požadovaná data nebo vazbu. Vstup: `$uid`, `$pageId`, `$tagId`, `$scope`. Výstup: hodnota typu `array`.
        $this->assertUuid($pageId);
        $this->assertUuid($tagId);
        if ($this->repository->getOwned($pageId, $uid) === null) {
            throw new \RuntimeException($this->l10n->t('Only the page owner can change tags'), 403);
        }
        if ($this->repository->getTagOwned($tagId, $uid) === null) {
            throw new \RuntimeException($this->l10n->t('Tag not found'), 404);
        }
        $scope = strtolower(trim($scope));
        if (!in_array($scope, ['single', 'subtree'], true)) {
            throw new \InvalidArgumentException($this->l10n->t('Invalid tag scope'));
        }
        $ids = [$pageId];
        if ($scope === 'subtree') $ids = array_merge($ids, $this->repository->descendantIds($uid, $pageId));
        $updated = 0;
        $this->repository->transactional(function () use ($ids, $tagId, &$updated): void {
            foreach ($ids as $id) if ($this->repository->removeTagFromPage((string)$id, $tagId)) $updated++;
        });
        return ['updated' => $updated, 'page' => $this->getPage($uid, $pageId), 'tags' => $this->listTags($uid)];
    }


    public function search(string $uid, string $query): array {
        // CZ-FUNKCE: Vyhledá odpovídající data. Vstup: `$uid`, `$query`. Výstup: hodnota typu `array`.
        $query = mb_substr(trim($query), 0, 255);
        $normalizedQuery = $this->normalizeSearchText($query);
        $pages = $this->repository->listVisible($uid);
        if ($normalizedQuery === '') {
            return $pages;
        }

        $matches = [];
        foreach ($pages as $page) {
            $content = $this->storage->readPage((string)$page['owner_uid'], (string)$page['file_path']);
            $searchableContent = (string)preg_replace('/<!--\s*homewiki-meta:.*?-->/su', ' ', $content);
            $tagText = implode(' ', array_map(static fn(array $tag): string => (string)($tag['name'] ?? ''), is_array($page['tags'] ?? null) ? $page['tags'] : []));
            $titleAndSlug = (string)$page['title'] . "\n" . (string)$page['slug'] . "\n" . $tagText;
            $normalizedTitle = $this->normalizeSearchText($titleAndSlug);
            $normalizedContent = $this->normalizeSearchText($searchableContent);

            $titleMatch = str_contains($normalizedTitle, $normalizedQuery);
            $contentPosition = mb_strpos($normalizedContent, $normalizedQuery);
            if (!$titleMatch && $contentPosition === false) {
                continue;
            }

            $plain = trim((string)preg_replace('/\s+/u', ' ', (string)preg_replace('/[`*_>#\[\]()-]+/u', ' ', $searchableContent)));
            $plainNormalized = $this->normalizeSearchText($plain);
            $plainPosition = mb_strpos($plainNormalized, $normalizedQuery);
            if ($plainPosition !== false) {
                $start = max(0, $plainPosition - 70);
                $page['search_snippet'] = ($start > 0 ? '…' : '')
                    . mb_substr($plain, $start, 200)
                    . (mb_strlen($plain) > $start + 200 ? '…' : '');
            } else {
                $page['search_snippet'] = '';
            }
            $page['search_match'] = $contentPosition !== false ? 'content' : 'title';
            $matches[] = $page;
        }
        return $matches;
    }

    private function normalizeSearchText(string $value): string {
        // CZ-FUNKCE: Normalizuje vstupní data do jednotného formátu. Vstup: `$value`. Výstup: hodnota typu `string`.
        $value = mb_strtolower($value);
        if (class_exists('Normalizer')) {
            $normalized = \Normalizer::normalize($value, \Normalizer::FORM_D);
            if (is_string($normalized)) {
                $value = (string)preg_replace('/\p{Mn}+/u', '', $normalized);
            }
        }
        return trim((string)preg_replace('/\s+/u', ' ', $value));
    }

    public function resolveAlias(string $uid, string $ownerUid, string $alias): ?array {
        // CZ-FUNKCE: Vyřeší a vrátí odpovídající hodnotu. Vstup: `$uid`, `$ownerUid`, `$alias`. Výstup: hodnota typu `?array`.
        $alias = trim(mb_substr($alias, 0, 512));
        if ($alias === '') {
            return null;
        }
        $page = $this->repository->resolveVisibleAlias($alias, $ownerUid, $uid);
        if ($page === null) {
            $slug = $this->normalizeSlug($alias);
            if ($slug !== $alias) {
                $page = $this->repository->resolveVisibleAlias($slug, $ownerUid, $uid);
            }
        }
        if ($page === null) {
            return null;
        }
        return [
            'id' => (string)$page['id'],
            'title' => (string)$page['title'],
            'slug' => (string)$page['slug'],
        ];
    }

    /** @return array<string,array{visible:bool,collapsed:bool}> */
    private function sidebarSectionDefaults(): array {
        // CZ-FUNKCE: Provádí dílčí operaci `sidebarSectionDefaults` v rámci Home Wiki. Vstup: žádný přímý parametr. Výstup: hodnota typu `array`.
        return [
            'pinned' => ['visible' => true, 'collapsed' => false],
            'recent' => ['visible' => true, 'collapsed' => false],
            'frequent' => ['visible' => false, 'collapsed' => false],
            'tags' => ['visible' => true, 'collapsed' => false],
            'pages' => ['visible' => true, 'collapsed' => false],
        ];
    }

    /** @return array<string,array{visible:bool,collapsed:bool}> */
    private function getSidebarSections(string $uid): array {
        // CZ-FUNKCE: Načte a vrátí požadovaná data. Vstup: `$uid`. Výstup: hodnota typu `array`.
        $sections = $this->sidebarSectionDefaults();
        $raw = trim($this->userConfig->getValueString($uid, Application::APP_ID, self::SIDEBAR_SECTIONS_CONFIG_KEY, ''));
        if ($raw === '') {
            return $sections;
        }
        try {
            $decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $sections;
        }
        if (!is_array($decoded)) {
            return $sections;
        }
        foreach ($sections as $key => $default) {
            $saved = $decoded[$key] ?? null;
            if (!is_array($saved)) {
                continue;
            }
            if (array_key_exists('visible', $saved)) {
                $sections[$key]['visible'] = (bool)$saved['visible'];
            }
            if (array_key_exists('collapsed', $saved)) {
                $sections[$key]['collapsed'] = (bool)$saved['collapsed'];
            }
        }
        return $sections;
    }

    /** @param array<string,mixed> $input */
    private function updateSidebarSections(string $uid, array $input): void {
        // CZ-FUNKCE: Aktualizuje existující data. Vstup: `$uid`, `$input`. Výstup: bez návratové hodnoty.
        $sections = $this->getSidebarSections($uid);
        foreach ($sections as $key => $_default) {
            $next = $input[$key] ?? null;
            if (!is_array($next)) {
                continue;
            }
            if (array_key_exists('visible', $next)) {
                $sections[$key]['visible'] = (bool)$next['visible'];
            }
            if (array_key_exists('collapsed', $next)) {
                $sections[$key]['collapsed'] = (bool)$next['collapsed'];
            }
        }
        $this->userConfig->setValueString(
            $uid,
            Application::APP_ID,
            self::SIDEBAR_SECTIONS_CONFIG_KEY,
            json_encode($sections, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }

    private function menuDensity(string $uid): string {
        // CZ-FUNKCE: Provádí dílčí operaci `menuDensity` v rámci Home Wiki. Vstup: `$uid`. Výstup: hodnota typu `string`.
        $density = trim($this->userConfig->getValueString(
            $uid,
            Application::APP_ID,
            self::MENU_DENSITY_CONFIG_KEY,
            'compact',
        ));
        return in_array($density, self::MENU_DENSITIES, true) ? $density : 'compact';
    }

    private function pageHeaderMode(string $uid): string {
        // CZ-FUNKCE: Provádí dílčí operaci `pageHeaderMode` v rámci Home Wiki. Vstup: `$uid`. Výstup: hodnota typu `string`.
        $mode = trim($this->userConfig->getValueString(
            $uid,
            Application::APP_ID,
            self::PAGE_HEADER_MODE_CONFIG_KEY,
            'scroll',
        ));
        return in_array($mode, self::PAGE_HEADER_MODES, true) ? $mode : 'scroll';
    }

    public function getStartupSettings(string $uid): array {
        // CZ-FUNKCE: Načte a vrátí požadovaná data. Vstup: `$uid`. Výstup: hodnota typu `array`.
        $isAdmin = $this->groupManager->isAdmin($uid);
        $personalId = trim($this->userConfig->getValueString($uid, Application::APP_ID, 'home_page_id', ''));
        $personalPage = $personalId !== '' ? $this->repository->get($personalId, $uid) : null;
        if ($personalId !== '' && $personalPage === null) {
            $this->userConfig->deleteUserConfig($uid, Application::APP_ID, 'home_page_id');
            $personalId = '';
        }

        $defaultId = trim($this->appConfig->getValueString(Application::APP_ID, 'default_home_page_id', ''));
        $defaultScope = $this->appConfig->getValueString(Application::APP_ID, 'default_home_scope', 'all');
        $defaultOwner = $this->appConfig->getValueString(Application::APP_ID, 'default_home_owner_uid', '');
        if (!in_array($defaultScope, ['owner', 'all'], true)) {
            $defaultScope = 'all';
        }
        $defaultRaw = $defaultId !== '' ? $this->repository->getRaw($defaultId) : null;
        if ($defaultId !== '' && $defaultRaw === null) {
            $this->appConfig->deleteKey(Application::APP_ID, 'default_home_page_id');
            $defaultId = '';
        }

        $effectiveId = $personalId;
        $source = $effectiveId !== '' ? 'personal' : 'none';
        if ($effectiveId === '' && $defaultId !== '' && ($defaultScope === 'all' || $defaultOwner === $uid)) {
            $defaultVisible = $this->repository->get($defaultId, $uid);
            if ($defaultVisible !== null) {
                $effectiveId = $defaultId;
                $source = 'admin';
            }
        }

        return [
            'personalHomePageId' => $personalId,
            'adminDefaultHomePageId' => $isAdmin ? $defaultId : '',
            'adminDefaultScope' => $isAdmin ? $defaultScope : '',
            'adminDefaultOwnerUid' => $isAdmin ? $defaultOwner : '',
            'effectiveHomePageId' => $effectiveId,
            'effectiveSource' => $source,
            'treeDragEnabled' => $this->userConfig->getValueString($uid, Application::APP_ID, 'tree_drag_enabled', '0') === '1',
            'sidebarSections' => $this->getSidebarSections($uid),
            'trashRetentionDays' => $this->trashRetentionDays($uid),
            'menuDensity' => $this->menuDensity($uid),
            'pageHeaderMode' => $this->pageHeaderMode($uid),
            'isAdmin' => $isAdmin,
        ];
    }

    public function updateStartupSettings(string $uid, array $input): array {
        // CZ-FUNKCE: Aktualizuje existující data. Vstup: `$uid`, `$input`. Výstup: hodnota typu `array`.
        // Settings API accepts partial updates. Tree UI toggles must never clear
        // an unrelated personal/admin start-page preference.
        if (array_key_exists('personalHomePageId', $input)) {
            $personalId = trim((string)$input['personalHomePageId']);
            if ($personalId !== '') {
                $this->assertUuid($personalId);
                if ($this->repository->get($personalId, $uid) === null) {
                    throw new \InvalidArgumentException($this->l10n->t('Selected start page is not available'));
                }
                $this->userConfig->setValueString($uid, Application::APP_ID, 'home_page_id', $personalId);
            } else {
                $this->userConfig->deleteUserConfig($uid, Application::APP_ID, 'home_page_id');
            }
        }

        if (array_key_exists('treeDragEnabled', $input)) {
            $this->userConfig->setValueString(
                $uid,
                Application::APP_ID,
                'tree_drag_enabled',
                (bool)$input['treeDragEnabled'] ? '1' : '0',
            );
        }

        if (array_key_exists('sidebarSections', $input)) {
            if (!is_array($input['sidebarSections'])) {
                throw new \InvalidArgumentException($this->l10n->t('Invalid sidebar section settings'));
            }
            $this->updateSidebarSections($uid, $input['sidebarSections']);
        }

        if (array_key_exists('trashRetentionDays', $input)) {
            $trashRetentionDays = (int)$input['trashRetentionDays'];
            if (!in_array($trashRetentionDays, self::TRASH_RETENTION_DAYS, true)) {
                throw new \InvalidArgumentException($this->l10n->t('Invalid trash retention period'));
            }
            $this->userConfig->setValueString(
                $uid,
                Application::APP_ID,
                self::TRASH_RETENTION_CONFIG_KEY,
                (string)$trashRetentionDays,
            );
        }

        if (array_key_exists('menuDensity', $input)) {
            $menuDensity = trim((string)$input['menuDensity']);
            if (!in_array($menuDensity, self::MENU_DENSITIES, true)) {
                throw new \InvalidArgumentException($this->l10n->t('Invalid menu density'));
            }
            $this->userConfig->setValueString(
                $uid,
                Application::APP_ID,
                self::MENU_DENSITY_CONFIG_KEY,
                $menuDensity,
            );
        }

        if (array_key_exists('pageHeaderMode', $input)) {
            $pageHeaderMode = trim((string)$input['pageHeaderMode']);
            if (!in_array($pageHeaderMode, self::PAGE_HEADER_MODES, true)) {
                throw new \InvalidArgumentException($this->l10n->t('Invalid page header mode'));
            }
            $this->userConfig->setValueString(
                $uid,
                Application::APP_ID,
                self::PAGE_HEADER_MODE_CONFIG_KEY,
                $pageHeaderMode,
            );
        }

        if (array_key_exists('adminDefaultHomePageId', $input) || array_key_exists('adminDefaultScope', $input)) {
            if (!$this->groupManager->isAdmin($uid)) {
                throw new \RuntimeException($this->l10n->t('Administrator permission required'), 403);
            }
            $defaultId = trim((string)($input['adminDefaultHomePageId'] ?? ''));
            $scope = (string)($input['adminDefaultScope'] ?? 'all');
            if (!in_array($scope, ['owner', 'all'], true)) {
                throw new \InvalidArgumentException($this->l10n->t('Invalid start page scope'));
            }
            if ($defaultId === '') {
                $this->appConfig->deleteKey(Application::APP_ID, 'default_home_page_id');
                $this->appConfig->deleteKey(Application::APP_ID, 'default_home_owner_uid');
                $this->appConfig->setValueString(Application::APP_ID, 'default_home_scope', $scope);
            } else {
                $this->assertUuid($defaultId);
                $page = $this->repository->get($defaultId, $uid);
                if ($page === null) {
                    throw new \InvalidArgumentException($this->l10n->t('Selected start page is not available'));
                }
                if ($scope === 'all' && (string)$page['visibility'] !== 'all') {
                    throw new \InvalidArgumentException($this->l10n->t('A start page for all users must be visible to all Nextcloud users'));
                }
                $this->appConfig->setValueString(Application::APP_ID, 'default_home_page_id', $defaultId);
                $this->appConfig->setValueString(Application::APP_ID, 'default_home_owner_uid', $uid);
                $this->appConfig->setValueString(Application::APP_ID, 'default_home_scope', $scope);
            }
        }

        return $this->getStartupSettings($uid);
    }

    public function backlinks(string $uid, string $id): array {
        // CZ-FUNKCE: Provádí dílčí operaci `backlinks` v rámci Home Wiki. Vstup: `$uid`, `$id`. Výstup: hodnota typu `array`.
        $this->assertUuid($id);
        $page = $this->repository->get($id, $uid);
        return $page === null ? [] : $this->repository->backlinks($id, $uid);
    }

    public function dashboard(string $uid): array {
        // CZ-FUNKCE: Provádí dílčí operaci `dashboard` v rámci Home Wiki. Vstup: `$uid`. Výstup: hodnota typu `array`.
        $pages = array_values(array_filter(
            $this->repository->listVisible($uid),
            static fn(array $page): bool => (bool)$page['pinned'],
        ));
        usort(
            $pages,
            static fn(array $left, array $right): int => ($left['sort_order'] <=> $right['sort_order'])
                ?: ($right['updated_at'] <=> $left['updated_at']),
        );

        return array_slice($pages, 0, 8);
    }

    /**
     * Export all live pages owned by the current user. Shared-in pages and the
     * Home Wiki trash are intentionally excluded. The archive is portable and
     * does not depend on internal Markdown filenames.
     *
     * @return array{filename:string,path:string,size:int,pageCount:int,mediaCount:int,attachmentCount:int}
     */
    public function exportWiki(string $uid): array {
        // CZ-FUNKCE: Připraví export požadovaných dat. Vstup: `$uid`. Výstup: hodnota typu `array`.
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException($this->l10n->t('ZIP support is not available on this server'), 503);
        }

        $pages = array_values(array_filter(
            $this->repository->listVisible($uid),
            static fn(array $page): bool => (string)$page['owner_uid'] === $uid,
        ));
        if ($pages === []) {
            throw new \RuntimeException($this->l10n->t('There are no owned pages to export'), 409);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'homewiki-export-');
        if ($tmp === false) {
            throw new \RuntimeException($this->l10n->t('Could not create export archive'));
        }

        $zip = new \ZipArchive();
        $opened = $zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        if ($opened !== true) {
            @unlink($tmp);
            throw new \RuntimeException($this->l10n->t('Could not create export archive'));
        }

        $manifestPages = [];
        $mediaCount = 0;
        $attachmentCount = 0;
        try {
            foreach ($pages as $page) {
                $id = (string)$page['id'];
                $content = $this->storage->readPage($uid, (string)$page['file_path']);
                $contentPath = 'pages/' . $id . '.md';
                if (!$zip->addFromString($contentPath, $content)) {
                    throw new \RuntimeException($this->l10n->t('Could not write export archive'));
                }

                $mediaManifest = [];
                foreach ($this->storage->listMediaFiles($uid, $id) as $mediaInfo) {
                    $media = $this->storage->readMedia($uid, $id, (string)$mediaInfo['name']);
                    if ($media === null) {
                        continue;
                    }
                    $mediaPath = 'media/' . $id . '/' . $mediaInfo['name'];
                    if (!$zip->addFromString($mediaPath, $media['content'])) {
                        throw new \RuntimeException($this->l10n->t('Could not write export archive'));
                    }
                    $mediaManifest[] = [
                        'name' => (string)$mediaInfo['name'],
                        'path' => $mediaPath,
                        'mime' => (string)$media['mime'],
                        'sha256' => hash('sha256', (string)$media['content']),
                    ];
                    unset($media);
                    $mediaCount++;
                }

                $attachmentManifest = [];
                foreach ($this->storage->listAttachmentFiles($uid, $id) as $attachmentInfo) {
                    $attachment = $this->storage->readAttachment($uid, $id, (string)$attachmentInfo['name']);
                    if ($attachment === null) {
                        continue;
                    }
                    $attachmentPath = 'attachments/' . $id . '/' . $attachment['name'];
                    if (!$zip->addFromString($attachmentPath, $attachment['content'])) {
                        throw new \RuntimeException($this->l10n->t('Could not write export archive'));
                    }
                    $attachmentManifest[] = [
                        'name' => (string)$attachment['name'],
                        'path' => $attachmentPath,
                        'mime' => (string)$attachment['mime'],
                        'size' => (int)$attachment['size'],
                        'sha256' => hash('sha256', (string)$attachment['content']),
                    ];
                    unset($attachment);
                    $attachmentCount++;
                }

                $manifestPages[] = [
                    'id' => $id,
                    'parentId' => $page['parent_id'] !== null ? (string)$page['parent_id'] : null,
                    'title' => (string)$page['title'],
                    'slug' => (string)$page['slug'],
                    'visibility' => (string)$page['visibility'],
                    'pinned' => (bool)$page['pinned'],
                    'sortOrder' => (int)$page['sort_order'],
                    'createdAt' => (int)$page['created_at'],
                    'updatedAt' => (int)$page['updated_at'],
                    'revision' => max(1, (int)($page['revision'] ?? 1)),
                    'allPermissions' => $page['allPermissions'] ?? [],
                    'acl' => $this->repository->getAcl($id),
                    'aliases' => $this->repository->getAliases($id),
                    'contentPath' => $contentPath,
                    'contentSha256' => hash('sha256', $content),
                    'media' => $mediaManifest,
                    'attachments' => $attachmentManifest,
                    'tags' => array_values(array_map(static fn(array $tag): string => (string)$tag['name'], $this->repository->getTagsForPage($id))),
                ];
            }

            $ownedTags = $this->repository->listTagsOwned($uid);
            $manifest = [
                'format' => 'hc-homewiki-export',
                'formatVersion' => 3,
                'appVersion' => Application::VERSION,
                'exportedAt' => gmdate('c'),
                'sourceOwner' => $uid,
                'pageCount' => count($manifestPages),
                'mediaCount' => $mediaCount,
                'attachmentCount' => $attachmentCount,
                'tagCount' => count($ownedTags),
                'tags' => array_values(array_map(static fn(array $tag): array => ['name' => (string)$tag['name']], $ownedTags)),
                'notes' => [
                    'trashExcluded' => true,
                    'nextcloudFileVersionsExcluded' => true,
                ],
                'pages' => $manifestPages,
            ];
            $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (!$zip->addFromString('manifest.json', $manifestJson . "\n")) {
                throw new \RuntimeException($this->l10n->t('Could not write export archive'));
            }
        } catch (\Throwable $error) {
            $zip->close();
            @unlink($tmp);
            throw $error;
        }

        if (!$zip->close()) {
            @unlink($tmp);
            throw new \RuntimeException($this->l10n->t('Could not write export archive'));
        }

        $size = @filesize($tmp);
        if ($size === false) {
            @unlink($tmp);
            throw new \RuntimeException($this->l10n->t('Could not read export archive'));
        }

        return [
            'filename' => 'HomeWiki-export-' . gmdate('Y-m-d_His') . '.zip',
            'path' => $tmp,
            'size' => (int)$size,
            'pageCount' => count($manifestPages),
            'mediaCount' => $mediaCount,
            'attachmentCount' => $attachmentCount,
        ];
    }

    /**
     * Import either a native Home Wiki backup, a generic Markdown ZIP, or one
     * Markdown file. Native backup restore requires an empty owned Wiki so IDs,
     * aliases and media links can be restored exactly.
     */
    public function importWiki(string $uid, array $uploadedFile): array {
        return $this->storage->withOwnerMutationLock($uid, fn(): array => $this->importWikiLocked($uid, $uploadedFile));
    }

    private function importWikiLocked(string $uid, array $uploadedFile): array {
        // CZ-FUNKCE: Načte a zpracuje importovaná data. Vstup: `$uid`, `$uploadedFile`. Výstup: hodnota typu `array`.
        if (($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException($this->l10n->t('Upload failed'));
        }
        $tmpPath = (string)($uploadedFile['tmp_name'] ?? '');
        $originalName = trim((string)($uploadedFile['name'] ?? ''));
        $size = (int)($uploadedFile['size'] ?? 0);
        if ($tmpPath === '' || $size <= 0 || $size > self::MAX_IMPORT_ARCHIVE_BYTES) {
            throw new \InvalidArgumentException($this->l10n->t('Import file is too large or empty'));
        }

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($extension === 'md' || $extension === 'markdown') {
            if ($size > self::MAX_CONTENT_BYTES) {
                throw new \InvalidArgumentException($this->l10n->t('Imported Markdown content is too large'));
            }
            $content = @file_get_contents($tmpPath, false, null, 0, self::MAX_CONTENT_BYTES + 1);
            if ($content === false) {
                throw new \InvalidArgumentException($this->l10n->t('Could not read import file'));
            }
            $title = $this->titleFromMarkdownPath($originalName, $content);
            $page = $this->createPage($uid, [
                'title' => $title,
                'content' => $this->normalizeContent($content),
                'visibility' => 'private',
            ]);
            return ['mode' => 'markdown', 'imported' => 1, 'ignoredFiles' => 0, 'skippedAcl' => 0, 'page' => $page];
        }

        if ($extension !== 'zip') {
            throw new \InvalidArgumentException($this->l10n->t('Import a Home Wiki ZIP, Markdown ZIP, or Markdown file'));
        }
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException($this->l10n->t('ZIP support is not available on this server'), 503);
        }

        $zip = new \ZipArchive();
        $opened = $zip->open($tmpPath, \ZipArchive::RDONLY);
        if ($opened !== true) {
            throw new \InvalidArgumentException($this->l10n->t('The ZIP archive could not be opened'));
        }
        try {
            $this->preflightArchive($zip);
            $manifestRaw = $zip->locateName('manifest.json') === false
                ? false : $this->readArchiveEntry($zip, 'manifest.json', self::MAX_IMPORT_MANIFEST_BYTES);
            if (is_string($manifestRaw)) {
                try {
                    $manifest = json_decode($manifestRaw, true, 64, JSON_THROW_ON_ERROR);
                } catch (\JsonException $exception) {
                    throw new \InvalidArgumentException($this->l10n->t('Home Wiki export manifest is invalid'), 0, $exception);
                }
                if (is_array($manifest) && ($manifest['format'] ?? null) === 'hc-homewiki-export') {
                    return $this->restoreNativeWikiArchive($uid, $zip, $manifest);
                }
            }
            return $this->importMarkdownZip($uid, $zip);
        } finally {
            $zip->close();
        }
    }

    /**
     * Delete all live and trashed pages owned by the user. Pages owned by other
     * users and merely shared with this user are never touched.
     */
    public function deleteWholeWiki(string $uid): array {
        return $this->storage->withOwnerMutationLock($uid, fn(): array => $this->deleteWholeWikiLocked($uid));
    }

    private function deleteWholeWikiLocked(string $uid): array {
        // CZ-FUNKCE: Smaže požadovaná data. Vstup: `$uid`. Výstup: hodnota typu `array`.
        $ownedPages = array_values(array_filter(
            $this->repository->listVisible($uid),
            static fn(array $page): bool => (string)$page['owner_uid'] === $uid,
        ));
        $trash = $this->repository->listTrashOwned($uid);
        $pageIds = array_map(static fn(array $page): string => (string)$page['id'], $ownedPages);
        $stagedName = $this->storage->stageWikiRootForDeletion($uid);

        try {
            $this->repository->transactional(function () use ($pageIds, $trash, $uid): void {
                foreach ($pageIds as $id) {
                    $this->repository->delete($id);
                }
                foreach ($trash as $row) {
                    $this->repository->deleteTrashRecord((string)$row['id']);
                }
                $this->repository->deleteAllTagsOwned($uid);
            });
        } catch (\Throwable $error) {
            try {
                $this->storage->restoreStagedWikiRoot($uid, $stagedName);
            } catch (\Throwable $restoreError) {
                $this->logger->critical('Home Wiki whole-Wiki delete rollback failed.', [
                    'exception' => $restoreError,
                    'originalException' => $error,
                ]);
            }
            throw $error;
        }

        try {
            foreach (['home_page_id', self::RECENT_CONFIG_KEY, self::SIDEBAR_SECTIONS_CONFIG_KEY, 'tree_drag_enabled'] as $key) {
                $this->userConfig->deleteUserConfig($uid, Application::APP_ID, $key);
            }
            if ($this->appConfig->getValueString(Application::APP_ID, 'default_home_owner_uid', '') === $uid) {
                $this->appConfig->deleteKey(Application::APP_ID, 'default_home_page_id');
                $this->appConfig->deleteKey(Application::APP_ID, 'default_home_owner_uid');
            }
        } catch (\Throwable $configError) {
            // Content deletion has already committed. A stale UI preference must
            // never prevent the staged Files tree from being purged.
            $this->logger->warning('Home Wiki could not clear all user settings after whole-Wiki delete.', [
                'exception' => $configError,
                'uid' => $uid,
            ]);
        }

        $cleanupPending = false;
        try {
            $this->storage->purgeStagedWikiRoot($uid, $stagedName);
        } catch (\Throwable $cleanupError) {
            $cleanupPending = true;
            $this->logger->warning('Home Wiki content was deleted but staged Files cleanup failed.', [
                'exception' => $cleanupError,
                'stagedName' => $stagedName,
            ]);
        }

        return [
            'deletedPages' => count($ownedPages),
            'deletedTrash' => count($trash),
            'cleanupPending' => $cleanupPending,
        ];
    }

    public function exportPage(string $uid, string $id): ?array {
        // CZ-FUNKCE: Připraví export požadovaných dat. Vstup: `$uid`, `$id`. Výstup: hodnota typu `?array`.
        $this->assertUuid($id);
        $page = $this->repository->get($id, $uid);
        if ($page === null) {
            return null;
        }

        return [
            'filename' => $this->safeDownloadName((string)$page['slug']) . '.md',
            'content' => $this->storage->readPage((string)$page['owner_uid'], (string)$page['file_path']),
        ];
    }

    public function uploadImage(string $uid, string $id, array $uploadedFile): array {
        // CZ-FUNKCE: Provádí dílčí operaci `uploadImage` v rámci Home Wiki. Vstup: `$uid`, `$id`, `$uploadedFile`. Výstup: hodnota typu `array`.
        $this->assertUuid($id);
        $page = $this->repository->get($id, $uid);
        if ($page === null) {
            throw new \RuntimeException($this->l10n->t('Page not found'), 404);
        }
        if (!(bool)$page['permissions']['edit']) {
            throw new \RuntimeException($this->l10n->t('Edit permission required'), 403);
        }
        if (($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException($this->l10n->t('Upload failed'));
        }

        return $this->storage->storeMedia(
            (string)$page['owner_uid'],
            $id,
            (string)($uploadedFile['name'] ?? 'image'),
            (string)($uploadedFile['tmp_name'] ?? ''),
            (int)($uploadedFile['size'] ?? 0),
        );
    }

    public function listAttachments(string $uid, string $id): array {
        // CZ-FUNKCE: Vrací seznam dat pro danou operaci. Vstup: `$uid`, `$id`. Výstup: hodnota typu `array`.
        $this->assertUuid($id);
        $page = $this->repository->get($id, $uid);
        if ($page === null) {
            throw new \RuntimeException($this->l10n->t('Page not found'), 404);
        }
        return [
            'attachments' => $this->storage->listAttachmentFiles((string)$page['owner_uid'], $id),
            'canEdit' => (bool)$page['permissions']['edit'],
            'canDelete' => (bool)$page['permissions']['delete'],
        ];
    }

    public function uploadAttachment(string $uid, string $id, array $uploadedFile): array {
        // CZ-FUNKCE: Provádí dílčí operaci `uploadAttachment` v rámci Home Wiki. Vstup: `$uid`, `$id`, `$uploadedFile`. Výstup: hodnota typu `array`.
        $this->assertUuid($id);
        $page = $this->repository->get($id, $uid);
        if ($page === null) {
            throw new \RuntimeException($this->l10n->t('Page not found'), 404);
        }
        if (!(bool)$page['permissions']['edit']) {
            throw new \RuntimeException($this->l10n->t('Edit permission required'), 403);
        }
        if (($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException($this->l10n->t('Upload failed'));
        }
        return $this->storage->storeAttachment(
            (string)$page['owner_uid'],
            $id,
            (string)($uploadedFile['name'] ?? 'attachment'),
            (string)($uploadedFile['tmp_name'] ?? ''),
            (int)($uploadedFile['size'] ?? 0),
        );
    }

    public function downloadAttachment(string $uid, string $id, string $name): ?array {
        // CZ-FUNKCE: Provádí dílčí operaci `downloadAttachment` v rámci Home Wiki. Vstup: `$uid`, `$id`, `$name`. Výstup: hodnota typu `?array`.
        $this->assertUuid($id);
        $page = $this->repository->get($id, $uid);
        if ($page === null) return null;
        return $this->storage->readAttachment((string)$page['owner_uid'], $id, $name);
    }

    public function deleteAttachment(string $uid, string $id, string $name): bool {
        // CZ-FUNKCE: Smaže požadovaná data. Vstup: `$uid`, `$id`, `$name`. Výstup: hodnota typu `bool`.
        $this->assertUuid($id);
        $page = $this->repository->get($id, $uid);
        if ($page === null) {
            throw new \RuntimeException($this->l10n->t('Page not found'), 404);
        }
        if (!(bool)$page['permissions']['delete']) {
            throw new \RuntimeException($this->l10n->t('Delete permission required'), 403);
        }
        return $this->storage->deleteAttachment((string)$page['owner_uid'], $id, $name);
    }

    public function displayMedia(string $uid, string $id, string $name): ?array {
        // CZ-FUNKCE: Provádí dílčí operaci `displayMedia` v rámci Home Wiki. Vstup: `$uid`, `$id`, `$name`. Výstup: hodnota typu `?array`.
        $this->assertUuid($id);
        $page = $this->repository->get($id, $uid);
        if ($page === null) {
            return null;
        }
        return $this->storage->readMedia((string)$page['owner_uid'], $id, $name);
    }

    /** Give existing pre-metadata pages the same portable index as new pages. */
    public function snapshotPages(string $uid): int {
        // CZ-FUNKCE: Provádí dílčí operaci `snapshotPages` v rámci Home Wiki. Vstup: `$uid`. Výstup: hodnota typu `int`.
        $updated = 0;
        foreach ($this->repository->listVisible($uid) as $page) {
            if ((string)$page['owner_uid'] !== $uid) continue;
            $path = (string)$page['file_path'];
            $raw = $this->storage->readRawPage($uid, $path);
            if (str_starts_with($raw, '<!-- homewiki-meta: ')) continue;
            $this->storage->writePage($uid, $path, $raw, $this->backupMetadata($page, $this->repository->getAcl((string)$page['id']), $raw));
            $updated++;
        }
        return $updated;
    }

    /** Add missing database rows from the signed-in owner's own Markdown files; never overwrite rows. */
    public function recoverPages(string $uid, bool $apply): array {
        return $this->storage->withOwnerMutationLock($uid, fn(): array => $this->recoverPagesLocked($uid, $apply));
    }

    private function recoverPagesLocked(string $uid, bool $apply): array {
        // CZ-FUNKCE: Provádí dílčí operaci `recoverPages` v rámci Home Wiki. Vstup: `$uid`, `$apply`. Výstup: hodnota typu `array`.
        $result = ['available' => 0, 'restored' => 0, 'skipped' => 0];
        $parents = [];
        $restoredContent = [];
        foreach ($this->storage->listPageBackups($uid) as $backup) {
            $meta = $backup['metadata'];
            $content = $backup['content'];
            $path = $backup['path'];
            try {
                $id = (string)($meta['id'] ?? '');
                $this->assertUuid($id);
                $slug = (string)($meta['slug'] ?? '');
                $title = $this->normalizeTitle($meta['title'] ?? '');
                $visibility = (string)($meta['visibility'] ?? 'private');
                if (($meta['owner_uid'] ?? '') !== $uid || $slug !== $this->normalizeSlug($slug)
                    || $path !== $this->storage->pagePath($id, $slug)
                    || !in_array($visibility, ['private', 'shared', 'all'], true)
                    || strlen($content) > self::MAX_CONTENT_BYTES
                    || !hash_equals((string)($meta['sha256'] ?? ''), hash('sha256', $content))
                    || $this->repository->getRaw($id) !== null
                    || $this->repository->slugExistsForOwner($uid, $slug)) {
                    $result['skipped']++;
                    continue;
                }
                $acl = $visibility !== 'private' ? $this->normalizeAcl($meta['acl'] ?? []) : [];
                $allPermissions = $this->normalizeAllPermissions($meta['allPermissions'] ?? [
                    'edit' => $meta['all_can_edit'] ?? false,
                    'delete' => $meta['all_can_delete'] ?? false,
                    'share' => $meta['all_can_share'] ?? false,
                ], $visibility);
                $page = [
                    'id' => $id, 'owner_uid' => $uid, 'parent_id' => null,
                    'title' => $title, 'slug' => $slug, 'file_path' => $path,
                    'visibility' => $visibility, 'pinned' => (bool)($meta['pinned'] ?? false),
                    'sort_order' => $this->normalizeSortOrder($meta['sort_order'] ?? 0),
                    'created_at' => max(0, (int)($meta['created_at'] ?? 0)),
                    'updated_at' => max(0, (int)($meta['updated_at'] ?? 0)),
                    'revision' => max(1, (int)($meta['revision'] ?? 1)),
                    'all_can_edit' => $allPermissions['edit'],
                    'all_can_delete' => $allPermissions['delete'],
                    'all_can_share' => $allPermissions['share'],
                ];
                $result['available']++;
                if (!$apply) continue;
                $this->repository->transactional(function () use ($page, $id, $acl, $uid, $content): void {
                    $this->repository->insert($page);
                    $this->repository->replaceAcl($id, $acl);
                    $this->repository->rebuildLinks($id, $uid, $content);
                });
                $result['restored']++;
                $parents[$id] = (string)($meta['parent_id'] ?? '');
                $restoredContent[$id] = $content;
            } catch (\Throwable $error) {
                $result['skipped']++;
                $this->logger->warning('Home Wiki skipped an invalid page backup.', ['path' => $path, 'exception' => $error]);
            }
        }
        foreach ($parents as $id => $parentId) {
            if ($parentId === '' || $parentId === $id || $this->repository->getOwned($parentId, $uid) === null) continue;
            $page = $this->repository->getOwned($id, $uid);
            if ($page !== null) {
                try {
                    $page['parent_id'] = $this->normalizeParentId($uid, $parentId, $id);
                    $this->repository->updateParentForRecovery($id, $page['parent_id']);
                } catch (\Throwable $error) {
                    $this->logger->warning('Home Wiki could not restore a page parent.', ['pageId' => $id, 'exception' => $error]);
                }
            }
        }
        foreach ($restoredContent as $id => $content) {
            try {
                $this->repository->rebuildLinks($id, $uid, $content);
            } catch (\Throwable $error) {
                $this->logger->warning('Home Wiki could not rebuild restored links.', ['pageId' => $id, 'exception' => $error]);
            }
        }
        return $result;
    }

    private function backupMetadata(array $page, array $acl, string $content): array {
        // CZ-FUNKCE: Provádí dílčí operaci `backupMetadata` v rámci Home Wiki. Vstup: `$page`, `$acl`, `$content`. Výstup: hodnota typu `array`.
        return [
            'format' => 1,
            'id' => (string)$page['id'], 'owner_uid' => (string)$page['owner_uid'],
            'parent_id' => $page['parent_id'] ?? null,
            'title' => (string)$page['title'], 'slug' => (string)$page['slug'],
            'visibility' => (string)$page['visibility'], 'pinned' => (bool)$page['pinned'],
            'sort_order' => (int)$page['sort_order'],
            'created_at' => (int)$page['created_at'], 'updated_at' => (int)$page['updated_at'],
            'revision' => max(1, (int)($page['revision'] ?? 1)),
            'allPermissions' => [
                'read' => (string)$page['visibility'] === 'all',
                'edit' => (string)$page['visibility'] === 'all' && (bool)($page['all_can_edit'] ?? false),
                'delete' => (string)$page['visibility'] === 'all' && (bool)($page['all_can_delete'] ?? false),
                'share' => (string)$page['visibility'] === 'all' && (bool)($page['all_can_share'] ?? false),
            ],
            'acl' => $acl, 'sha256' => hash('sha256', $content),
        ];
    }


    /**
     * Před načítáním ověří strukturu ZIPu, duplicity, počty položek a dekomprimované velikosti.
     * Vstup: otevřený archiv `$zip`.
     * Výstup: bez návratové hodnoty; nebezpečný nebo nadlimitní archiv odmítne výjimkou.
     */
    private function preflightArchive(\ZipArchive $zip): void {
        if ($zip->numFiles > self::MAX_IMPORT_ENTRIES) {
            throw new \InvalidArgumentException($this->l10n->t('The archive contains too many pages'));
        }
        $seen = [];
        $totals = ['content' => 0, 'media' => 0, 'attachments' => 0];
        $counts = ['content' => 0, 'media' => 0, 'attachments' => 0];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            if (!is_array($stat)) {
                throw new \InvalidArgumentException($this->l10n->t('Home Wiki export manifest is invalid'));
            }
            $name = (string)$stat['name'];
            $canonical = $this->safeArchivePath($name) ?? $name;
            if (isset($seen[$canonical])) {
                throw new \InvalidArgumentException($this->l10n->t('Home Wiki export manifest is invalid'));
            }
            $seen[$canonical] = true;
            $name = $canonical;
            if (str_ends_with($name, '/')) continue;
            $size = (int)$stat['size'];
            $type = null;
            $limit = 0;
            if ($name === 'manifest.json') $limit = self::MAX_IMPORT_MANIFEST_BYTES;
            elseif (str_starts_with($name, 'media/')) { $type = 'media'; $limit = 10 * 1024 * 1024; }
            elseif (str_starts_with($name, 'attachments/')) { $type = 'attachments'; $limit = 50 * 1024 * 1024; }
            elseif (preg_match('/\.(?:md|markdown)$/i', $name)) { $type = 'content'; $limit = self::MAX_CONTENT_BYTES; }
            else continue; // Generic imports do not decompress unrelated files.
            if ($size < 0 || $size > $limit) {
                throw new \InvalidArgumentException($this->l10n->t('Import file is too large or empty'));
            }
            if ($type === null) continue;
            $totals[$type] += $size;
            $counts[$type]++;
        }
        if ($totals['content'] > self::MAX_IMPORT_TOTAL_CONTENT_BYTES
            || $totals['media'] > self::MAX_IMPORT_TOTAL_MEDIA_BYTES
            || $totals['attachments'] > self::MAX_IMPORT_TOTAL_ATTACHMENT_BYTES
            || $counts['content'] > self::MAX_IMPORT_PAGES
            || $counts['media'] > self::MAX_IMPORT_MEDIA_FILES
            || $counts['attachments'] > self::MAX_IMPORT_ATTACHMENT_FILES) {
            throw new \InvalidArgumentException($this->l10n->t('Import file is too large or empty'));
        }
    }

    /**
     * Načte jednu položku ZIPu streamovaně s pevným limitem a ověřením deklarované velikosti.
     * Vstup: archiv `$zip`, cesta `$path` a maximální počet bajtů `$limit`.
     * Výstup: obsah položky jako `string`; nadlimitní nebo poškozený vstup odmítne.
     */
    private function readArchiveEntry(\ZipArchive $zip, string $path, int $limit): string {
        $stat = $zip->statName($path);
        if (!is_array($stat) || $limit < 0 || (int)$stat['size'] < 0 || (int)$stat['size'] > $limit) {
            throw new \InvalidArgumentException($this->l10n->t('Home Wiki export manifest is invalid'));
        }
        $stream = $zip->getStream($path);
        if (!is_resource($stream)) {
            throw new \InvalidArgumentException($this->l10n->t('Could not read import file'));
        }
        try {
            $content = stream_get_contents($stream, $limit + 1);
            if (!is_string($content) || strlen($content) > $limit || strlen($content) !== (int)$stat['size']) {
                throw new \InvalidArgumentException($this->l10n->t('Import file is too large or empty'));
            }
            return $content;
        } finally {
            fclose($stream);
        }
    }

    private function restoreNativeWikiArchive(string $uid, \ZipArchive $zip, array $manifest): array {
        // CZ-FUNKCE: Obnoví dříve odstraněná nebo verzovaná data. Vstup: `$uid`, `$zip`, `$manifest`. Výstup: hodnota typu `array`.
        if (!in_array((int)($manifest['formatVersion'] ?? 0), [1, 2, 3], true) || !is_array($manifest['pages'] ?? null)) {
            throw new \InvalidArgumentException($this->l10n->t('Unsupported Home Wiki export format'));
        }
        $existingOwned = array_values(array_filter(
            $this->repository->listVisible($uid),
            static fn(array $page): bool => (string)$page['owner_uid'] === $uid,
        ));
        if ($existingOwned !== [] || $this->repository->listTrashOwned($uid) !== [] || $this->storage->wikiHasFiles($uid)) {
            throw new \RuntimeException($this->l10n->t('A complete Home Wiki backup can only be restored into an empty Wiki'), 409);
        }

        $manifestPages = array_values($manifest['pages']);
        if (count($manifestPages) > self::MAX_IMPORT_PAGES) {
            throw new \InvalidArgumentException($this->l10n->t('The archive contains too many pages'));
        }

        $prepared = [];
        $ids = [];
        $slugs = [];
        $totalBytes = 0;
        $skippedAcl = 0;
        $totalMediaBytes = 0;
        $totalMediaFiles = 0;
        $totalAttachmentBytes = 0;
        $totalAttachmentFiles = 0;
        foreach ($manifestPages as $item) {
            if (!is_array($item)) {
                throw new \InvalidArgumentException($this->l10n->t('Home Wiki export manifest is invalid'));
            }
            $id = (string)($item['id'] ?? '');
            $this->assertUuid($id);
            if (isset($ids[$id]) || $this->repository->getRaw($id) !== null) {
                throw new \InvalidArgumentException($this->l10n->t('A page identifier from the backup already exists'));
            }
            $ids[$id] = true;
            $title = $this->normalizeTitle($item['title'] ?? '');
            $slug = $this->normalizeSlug($item['slug'] ?? $title);
            if (isset($slugs[$slug])) {
                throw new \InvalidArgumentException($this->l10n->t('The backup contains duplicate page addresses'));
            }
            $slugs[$slug] = true;
            $contentPath = $this->safeArchivePath((string)($item['contentPath'] ?? ''));
            if ($contentPath === null || $contentPath !== 'pages/' . $id . '.md') {
                throw new \InvalidArgumentException($this->l10n->t('Home Wiki export manifest is invalid'));
            }
            $content = $this->readArchiveEntry($zip, $contentPath, min(self::MAX_CONTENT_BYTES, self::MAX_IMPORT_TOTAL_CONTENT_BYTES - $totalBytes));
            if (!is_string($content)) {
                throw new \InvalidArgumentException($this->l10n->t('A page file is missing from the backup'));
            }
            $content = $this->normalizeContent($content);
            $totalBytes += strlen($content);
            if ($totalBytes > self::MAX_IMPORT_TOTAL_CONTENT_BYTES) {
                throw new \InvalidArgumentException($this->l10n->t('Imported Markdown content is too large'));
            }
            $expectedHash = strtolower(trim((string)($item['contentSha256'] ?? '')));
            if ($expectedHash !== '' && !hash_equals($expectedHash, hash('sha256', $content))) {
                throw new \InvalidArgumentException($this->l10n->t('A page file does not match the export manifest'));
            }

            $visibility = $this->normalizeVisibility($item['visibility'] ?? 'private');
            $allPermissions = $this->normalizeAllPermissions($item['allPermissions'] ?? [], $visibility);
            [$acl, $skipped] = $this->normalizeImportedAcl($item['acl'] ?? []);
            $skippedAcl += $skipped;
            $parentId = ($item['parentId'] ?? null) === null || ($item['parentId'] ?? '') === '' ? null : (string)$item['parentId'];
            if ($parentId !== null) {
                $this->assertUuid($parentId);
            }
            $media = [];
            foreach (is_array($item['media'] ?? null) ? $item['media'] : [] as $mediaItem) {
                if (!is_array($mediaItem)) continue;
                $name = trim((string)($mediaItem['name'] ?? ''));
                $path = $this->safeArchivePath((string)($mediaItem['path'] ?? ''));
                if ($name === '' || basename($name) !== $name || $path === null || $path !== 'media/' . $id . '/' . $name) {
                    throw new \InvalidArgumentException($this->l10n->t('Home Wiki export manifest is invalid'));
                }
                $mediaContent = $this->readArchiveEntry($zip, $path, min(10 * 1024 * 1024, self::MAX_IMPORT_TOTAL_MEDIA_BYTES - $totalMediaBytes));
                if (!is_string($mediaContent)) {
                    throw new \InvalidArgumentException($this->l10n->t('A media file is missing from the backup'));
                }
                $totalMediaBytes += strlen($mediaContent);
                $totalMediaFiles++;
                if ($totalMediaBytes > self::MAX_IMPORT_TOTAL_MEDIA_BYTES || $totalMediaFiles > self::MAX_IMPORT_MEDIA_FILES) {
                    throw new \InvalidArgumentException($this->l10n->t('Imported media is too large'));
                }
                $expectedMediaHash = strtolower(trim((string)($mediaItem['sha256'] ?? '')));
                if ($expectedMediaHash !== '' && !hash_equals($expectedMediaHash, hash('sha256', $mediaContent))) {
                    throw new \InvalidArgumentException($this->l10n->t('A media file does not match the export manifest'));
                }
                $media[] = ['name' => $name, 'path' => $path];
                unset($mediaContent);
            }

            $attachments = [];
            foreach (is_array($item['attachments'] ?? null) ? $item['attachments'] : [] as $attachmentItem) {
                if (!is_array($attachmentItem)) continue;
                $name = trim((string)($attachmentItem['name'] ?? ''));
                $path = $this->safeArchivePath((string)($attachmentItem['path'] ?? ''));
                if ($name === '' || basename($name) !== $name || $path === null || $path !== 'attachments/' . $id . '/' . $name) {
                    throw new \InvalidArgumentException($this->l10n->t('Home Wiki export manifest is invalid'));
                }
                $attachmentContent = $this->readArchiveEntry($zip, $path, min(50 * 1024 * 1024, self::MAX_IMPORT_TOTAL_ATTACHMENT_BYTES - $totalAttachmentBytes));
                if (!is_string($attachmentContent)) {
                    throw new \InvalidArgumentException($this->l10n->t('An attachment is missing from the backup'));
                }
                $totalAttachmentBytes += strlen($attachmentContent);
                $totalAttachmentFiles++;
                if ($totalAttachmentBytes > self::MAX_IMPORT_TOTAL_ATTACHMENT_BYTES || $totalAttachmentFiles > self::MAX_IMPORT_ATTACHMENT_FILES) {
                    throw new \InvalidArgumentException($this->l10n->t('Imported attachments are too large'));
                }
                $expectedAttachmentHash = strtolower(trim((string)($attachmentItem['sha256'] ?? '')));
                if ($expectedAttachmentHash !== '' && !hash_equals($expectedAttachmentHash, hash('sha256', $attachmentContent))) {
                    throw new \InvalidArgumentException($this->l10n->t('An attachment does not match the export manifest'));
                }
                $attachments[] = ['name' => $name, 'path' => $path];
                unset($attachmentContent);
            }

            $page = [
                'id' => $id,
                'owner_uid' => $uid,
                'parent_id' => $parentId,
                'title' => $title,
                'slug' => $slug,
                'file_path' => $this->storage->pagePath($id, $slug),
                'visibility' => $visibility,
                'pinned' => (bool)($item['pinned'] ?? false),
                'sort_order' => $this->normalizeSortOrder($item['sortOrder'] ?? 0),
                'created_at' => max(1, (int)($item['createdAt'] ?? time())),
                'updated_at' => max(1, (int)($item['updatedAt'] ?? time())),
                'revision' => max(1, (int)($item['revision'] ?? 1)),
                'all_can_edit' => $allPermissions['edit'],
                'all_can_delete' => $allPermissions['delete'],
                'all_can_share' => $allPermissions['share'],
            ];
            $aliases = [];
            foreach (is_array($item['aliases'] ?? null) ? $item['aliases'] : [] as $alias) {
                $alias = trim((string)$alias, " /\t\r\n");
                if ($alias !== '' && mb_strlen($alias) <= 512) {
                    $aliases[] = $alias;
                }
            }
            $pageTags = [];
            foreach (is_array($item['tags'] ?? null) ? $item['tags'] : [] as $tagName) {
                $pageTags[] = $this->normalizeTagName($tagName);
            }
            $prepared[$id] = ['page' => $page, 'acl' => $visibility !== 'private' ? $acl : [], 'aliases' => array_values(array_unique($aliases)), 'tags' => array_values(array_unique($pageTags)), 'contentPath' => $contentPath, 'media' => $media, 'attachments' => $attachments];
            unset($content);
        }

        $this->assertImportedTree($prepared);
        $manifestTagNames = [];
        foreach (is_array($manifest['tags'] ?? null) ? $manifest['tags'] : [] as $tagItem) {
            $tagName = is_array($tagItem) ? ($tagItem['name'] ?? '') : $tagItem;
            $manifestTagNames[] = $this->normalizeTagName($tagName);
        }
        $manifestTagNames = array_values(array_unique($manifestTagNames));

        $created = [];
        try {
            foreach ($prepared as $row) {
                $page = $row['page'];
                $content = $this->normalizeContent($this->readArchiveEntry($zip, $row['contentPath'], self::MAX_CONTENT_BYTES));
                $this->storage->createImportedPage($uid, (string)$page['file_path'], $content, $this->backupMetadata($page, $row['acl'], $content));
                $created[] = $page;
                unset($content);
                foreach ($row['media'] as $media) {
                    $this->storage->storeImportedMediaContent($uid, (string)$page['id'], (string)$media['name'], $this->readArchiveEntry($zip, $media['path'], 10 * 1024 * 1024));
                }
                foreach ($row['attachments'] as $attachment) {
                    $this->storage->storeImportedAttachmentContent($uid, (string)$page['id'], (string)$attachment['name'], $this->readArchiveEntry($zip, $attachment['path'], 50 * 1024 * 1024));
                }
            }
            $this->repository->transactional(function () use ($prepared, $uid, $manifestTagNames, $zip): void {
                foreach ($manifestTagNames as $tagName) {
                    $this->ensureOwnedTag($uid, $tagName);
                }
                foreach ($prepared as $row) {
                    $page = $row['page'];
                    $id = (string)$page['id'];
                    $this->repository->insert($page);
                    $this->repository->replaceAcl($id, $row['acl']);
                    $this->repository->restoreAliases($id, $uid, $row['aliases']);
                    foreach ($row['tags'] as $tagName) {
                        $tag = $this->ensureOwnedTag($uid, $tagName);
                        $this->repository->addTagToPage($id, (string)$tag['id']);
                    }
                }
                foreach ($prepared as $row) {
                    $this->repository->rebuildLinks((string)$row['page']['id'], $uid, $this->normalizeContent($this->readArchiveEntry($zip, $row['contentPath'], self::MAX_CONTENT_BYTES)));
                }
            });
        } catch (\Throwable $error) {
            foreach (array_reverse($created) as $page) {
                try {
                    $this->storage->deleteImportedPage($uid, (string)$page['id'], (string)$page['file_path']);
                } catch (\Throwable $cleanupError) {
                    $this->logger->warning('Home Wiki failed to clean an imported page.', ['exception' => $cleanupError]);
                }
            }
            throw $error;
        }

        return [
            'mode' => 'backup',
            'imported' => count($prepared),
            'ignoredFiles' => 0,
            'skippedAcl' => $skippedAcl,
        ];
    }

    private function importMarkdownZip(string $uid, \ZipArchive $zip): array {
        // CZ-FUNKCE: Načte a zpracuje importovaná data. Vstup: `$uid`, `$zip`. Výstup: hodnota typu `array`.
        $files = [];
        $ignored = 0;
        $totalBytes = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            if (!is_array($stat)) continue;
            $path = $this->safeArchivePath((string)($stat['name'] ?? ''));
            if ($path === null || str_ends_with($path, '/')) {
                continue;
            }
            if (!preg_match('/\\.(?:md|markdown)$/i', $path)) {
                if ($path !== 'manifest.json') $ignored++;
                continue;
            }
            if (count($files) >= self::MAX_IMPORT_PAGES) {
                throw new \InvalidArgumentException($this->l10n->t('The archive contains too many pages'));
            }
            $content = $this->readArchiveEntry($zip, (string)$stat['name'], min(self::MAX_CONTENT_BYTES, self::MAX_IMPORT_TOTAL_CONTENT_BYTES - $totalBytes));
            if (!is_string($content)) {
                throw new \InvalidArgumentException($this->l10n->t('Could not read Markdown file from ZIP'));
            }
            $content = $this->normalizeContent($content);
            $totalBytes += strlen($content);
            if ($totalBytes > self::MAX_IMPORT_TOTAL_CONTENT_BYTES) {
                throw new \InvalidArgumentException($this->l10n->t('Imported Markdown content is too large'));
            }
            $files[$path] = (string)$stat['name'];
            unset($content);
        }
        if ($files === []) {
            throw new \InvalidArgumentException($this->l10n->t('No Markdown files were found in the ZIP archive'));
        }
        if (count($files) > self::MAX_IMPORT_PAGES) {
            throw new \InvalidArgumentException($this->l10n->t('The archive contains too many pages'));
        }

        uksort($files, static function (string $left, string $right): int {
            $leftDepth = substr_count($left, '/');
            $rightDepth = substr_count($right, '/');
            if ($leftDepth !== $rightDepth) return $leftDepth <=> $rightDepth;
            $leftIndex = preg_match('~(?:^|/)index\\.(?:md|markdown)$~i', $left) ? 0 : 1;
            $rightIndex = preg_match('~(?:^|/)index\\.(?:md|markdown)$~i', $right) ? 0 : 1;
            return ($leftIndex <=> $rightIndex) ?: strnatcasecmp($left, $right);
        });

        $createdByPath = [];
        $created = [];
        try {
            foreach ($files as $path => $entryName) {
                $content = $this->readArchiveEntry($zip, $entryName, self::MAX_CONTENT_BYTES);
                $parentId = $this->markdownImportParentId($path, $createdByPath);
                $page = $this->createPage($uid, [
                    'title' => $this->titleFromMarkdownPath($path, $content),
                    'content' => $content,
                    'visibility' => 'private',
                    'parentId' => $parentId,
                ]);
                $createdByPath[$path] = (string)$page['id'];
                $created[] = $page;
            }
        } catch (\Throwable $error) {
            foreach (array_reverse($created) as $page) {
                try {
                    $this->repository->delete((string)$page['id']);
                    $this->storage->deletePage($uid, (string)$page['file_path']);
                } catch (\Throwable $cleanupError) {
                    $this->logger->warning('Home Wiki Markdown import rollback failed.', [
                        'exception' => $cleanupError,
                        'pageId' => $page['id'] ?? null,
                    ]);
                }
            }
            throw $error;
        }

        return ['mode' => 'markdown', 'imported' => count($created), 'ignoredFiles' => $ignored, 'skippedAcl' => 0];
    }

    /** @return array{0:list<array<string,mixed>>,1:int} */
    private function normalizeImportedAcl(mixed $value): array {
        // CZ-FUNKCE: Normalizuje vstupní data do jednotného formátu. Vstup: `$value`. Výstup: hodnota typu `array`.
        if (!is_array($value)) return [[], 0];
        $result = [];
        $seen = [];
        $skipped = 0;
        foreach ($value as $entry) {
            if (!is_array($entry)) continue;
            $type = ($entry['type'] ?? '') === 'group' ? 'group' : 'user';
            $id = trim((string)($entry['id'] ?? ''));
            if ($id === '' || mb_strlen($id) > 64) { $skipped++; continue; }
            $key = $type . ':' . $id;
            if (isset($seen[$key])) continue;
            if (($type === 'user' && $this->userManager->get($id) === null)
                || ($type === 'group' && $this->groupManager->get($id) === null)) {
                $skipped++;
                continue;
            }
            $canEdit = (bool)($entry['canEdit'] ?? false);
            $canDelete = (bool)($entry['canDelete'] ?? false);
            $canShare = (bool)($entry['canShare'] ?? false);
            $canRead = (bool)($entry['canRead'] ?? true) || $canEdit || $canDelete || $canShare;
            $seen[$key] = true;
            $result[] = [
                'type' => $type, 'id' => $id, 'canRead' => $canRead,
                'canEdit' => $canRead && $canEdit,
                'canDelete' => $canRead && $canDelete,
                'canShare' => $canRead && $canShare,
            ];
        }
        return [$result, $skipped];
    }

    /** @param array<string,array{page:array<string,mixed>}> $prepared */
    private function assertImportedTree(array $prepared): void {
        // CZ-FUNKCE: Ověří požadovanou podmínku a při chybě operaci zastaví. Vstup: `$prepared`. Výstup: bez návratové hodnoty.
        foreach ($prepared as $id => $row) {
            $parentId = $row['page']['parent_id'] ?? null;
            if ($parentId !== null && (!isset($prepared[(string)$parentId]) || (string)$parentId === (string)$id)) {
                throw new \InvalidArgumentException($this->l10n->t('The backup contains an invalid page tree'));
            }
        }
        foreach (array_keys($prepared) as $startId) {
            $seen = [];
            $current = (string)$startId;
            while ($current !== '') {
                if (isset($seen[$current])) {
                    throw new \InvalidArgumentException($this->l10n->t('The backup contains a cyclic page tree'));
                }
                $seen[$current] = true;
                $parent = $prepared[$current]['page']['parent_id'] ?? null;
                $current = $parent !== null ? (string)$parent : '';
            }
        }
    }

    private function safeArchivePath(string $path): ?string {
        // CZ-FUNKCE: Provádí dílčí operaci `safeArchivePath` v rámci Home Wiki. Vstup: `$path`. Výstup: hodnota typu `?string`.
        if ($path === '' || str_contains($path, "\0")) return null;
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('~/+~', '/', $path) ?? '';
        if ($path === '' || str_starts_with($path, '/') || preg_match('~(?:^|/)\.\.(?:/|$)~', $path)) return null;
        while (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }
        return $path !== '' ? $path : null;
    }

    private function titleFromMarkdownPath(string $path, string $content): string {
        // CZ-FUNKCE: Provádí dílčí operaci `titleFromMarkdownPath` v rámci Home Wiki. Vstup: `$path`, `$content`. Výstup: hodnota typu `string`.
        if (preg_match('/^\\s*#\\s+(.+?)\\s*$/mu', $content, $match)) {
            $candidate = trim(preg_replace('/\\s+#*\\s*$/u', '', (string)$match[1]) ?? '');
            if ($candidate !== '') return $this->normalizeTitle(mb_substr($candidate, 0, 255));
        }
        $name = pathinfo($path, PATHINFO_FILENAME);
        if (mb_strtolower($name) === 'index') {
            $parent = basename(dirname(str_replace('\\\\', '/', $path)));
            if ($parent !== '' && $parent !== '.') $name = $parent;
        }
        $name = trim(str_replace(['_', '-'], ' ', $name));
        return $this->normalizeTitle($name !== '' ? $name : $this->l10n->t('Imported page'));
    }

    /** @param array<string,string> $createdByPath */
    private function markdownImportParentId(string $path, array $createdByPath): ?string {
        // CZ-FUNKCE: Provádí dílčí operaci `markdownImportParentId` v rámci Home Wiki. Vstup: `$path`, `$createdByPath`. Výstup: hodnota typu `?string`.
        $directory = dirname($path);
        while ($directory !== '.' && $directory !== '') {
            foreach ([$directory . '/index.md', $directory . '/index.markdown', $directory . '.md', $directory . '.markdown'] as $candidate) {
                if ($candidate !== $path && isset($createdByPath[$candidate])) return $createdByPath[$candidate];
            }
            $next = dirname($directory);
            if ($next === $directory) break;
            $directory = $next;
        }
        return null;
    }

    private function normalizeTagName(mixed $value): string {
        // CZ-FUNKCE: Normalizuje vstupní data do jednotného formátu. Vstup: `$value`. Výstup: hodnota typu `string`.
        $name = trim((string)$value);
        $name = ltrim($name, "# \t\r\n");
        $name = trim((string)preg_replace('/\s+/u', ' ', $name));
        if ($name === '') {
            throw new \InvalidArgumentException($this->l10n->t('Tag name is required'));
        }
        if (mb_strlen($name) > self::MAX_TAG_NAME_LENGTH) {
            throw new \InvalidArgumentException($this->l10n->t('Tag name is too long'));
        }
        if (preg_match('/[\x00-\x1F\x7F]/u', $name)) {
            throw new \InvalidArgumentException($this->l10n->t('Tag name contains invalid characters'));
        }
        return $name;
    }

    private function normalizeTagKey(string $name): string {
        // CZ-FUNKCE: Normalizuje vstupní data do jednotného formátu. Vstup: `$name`. Výstup: hodnota typu `string`.
        $name = mb_strtolower(trim($name));
        if (class_exists('Normalizer')) {
            $normalized = \Normalizer::normalize($name, \Normalizer::FORM_C);
            if (is_string($normalized)) $name = $normalized;
        }
        return trim((string)preg_replace('/\s+/u', ' ', $name));
    }

    /** @return array{id:string,ownerUid:string,name:string,count:int,own:bool} */
    private function ensureOwnedTag(string $uid, string $name): array {
        // CZ-FUNKCE: Zajistí požadovaný stav nebo strukturu. Vstup: `$uid`, `$name`. Výstup: hodnota typu `array`.
        $name = $this->normalizeTagName($name);
        $normalized = $this->normalizeTagKey($name);
        $existing = $this->repository->getTagByNormalizedName($uid, $normalized);
        if ($existing !== null) {
            return [
                'id' => (string)$existing['id'],
                'ownerUid' => $uid,
                'name' => (string)$existing['name'],
                'count' => 0,
                'own' => true,
            ];
        }
        $id = $this->repository->newId();
        $now = time();
        try {
            $this->repository->insertTag([
                'id' => $id,
                'owner_uid' => $uid,
                'name' => $name,
                'normalized_name' => $normalized,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (\Throwable $error) {
            // The unique owner/name index is the final concurrency guard. If
            // another request created the same normalized tag between our
            // lookup and insert, reuse that row instead of returning HTTP 500.
            $raced = $this->repository->getTagByNormalizedName($uid, $normalized);
            if ($raced === null) {
                throw $error;
            }
            return [
                'id' => (string)$raced['id'],
                'ownerUid' => $uid,
                'name' => (string)$raced['name'],
                'count' => 0,
                'own' => true,
            ];
        }
        return ['id' => $id, 'ownerUid' => $uid, 'name' => $name, 'count' => 0, 'own' => true];
    }

    private function normalizeTitle(mixed $value): string {
        // CZ-FUNKCE: Normalizuje vstupní data do jednotného formátu. Vstup: `$value`. Výstup: hodnota typu `string`.
        $title = trim((string)$value);
        if ($title === '') {
            throw new \InvalidArgumentException($this->l10n->t('Please enter a page title.'));
        }
        if (mb_strlen($title) > 255) {
            throw new \InvalidArgumentException($this->l10n->t('Page title is too long'));
        }

        return $title;
    }

    private function normalizeSlug(mixed $value): string {
        // CZ-FUNKCE: Normalizuje vstupní data do jednotného formátu. Vstup: `$value`. Výstup: hodnota typu `string`.
        $slug = trim((string)$value);
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $slug);
        if (is_string($ascii) && $ascii !== '') {
            $slug = $ascii;
        }
        $slug = strtolower($slug);
        $slug = preg_replace('~[^a-z0-9/_-]+~', '-', $slug) ?? 'page';
        $slug = preg_replace('~-+~', '-', $slug) ?? $slug;
        $slug = trim($slug, '/-');

        return mb_substr($slug !== '' ? $slug : 'page', 0, 255);
    }

    private function uniqueSlug(string $ownerUid, string $baseSlug, ?string $excludeId = null): string {
        // CZ-FUNKCE: Provádí dílčí operaci `uniqueSlug` v rámci Home Wiki. Vstup: `$ownerUid`, `$baseSlug`, `$excludeId`. Výstup: hodnota typu `string`.
        $slug = $baseSlug;
        $suffix = 2;
        while ($this->repository->slugExistsForOwner($ownerUid, $slug, $excludeId)) {
            $suffixText = '-' . $suffix;
            $slug = mb_substr($baseSlug, 0, 255 - strlen($suffixText)) . $suffixText;
            $suffix++;
            if ($suffix > 9999) {
                throw new \RuntimeException($this->l10n->t('Could not create a unique page address'));
            }
        }

        return $slug;
    }

    private function normalizeParentId(string $ownerUid, mixed $value, ?string $currentId = null): ?string {
        // CZ-FUNKCE: Normalizuje vstupní data do jednotného formátu. Vstup: `$ownerUid`, `$value`, `$currentId`. Výstup: hodnota typu `?string`.
        $parentId = trim((string)$value);
        if ($parentId === '') {
            return null;
        }
        $this->assertUuid($parentId);
        if ($currentId !== null && $parentId === $currentId) {
            throw new \InvalidArgumentException($this->l10n->t('A page cannot be its own parent'));
        }
        $visited = $currentId !== null ? [$currentId => true] : [];
        $next = $parentId;
        while ($next !== '') {
            if (isset($visited[$next])) {
                throw new \InvalidArgumentException($this->l10n->t('A page cannot be placed below its own child'));
            }
            $visited[$next] = true;
            $ancestor = $this->repository->getOwned($next, $ownerUid);
            if ($ancestor === null) {
                throw new \InvalidArgumentException($this->l10n->t('Parent page must belong to the same owner'));
            }
            $next = (string)($ancestor['parent_id'] ?? '');
        }

        return $parentId;
    }

    private function normalizeVisibility(mixed $value): string {
        // CZ-FUNKCE: Normalizuje vstupní data do jednotného formátu. Vstup: `$value`. Výstup: hodnota typu `string`.
        $visibility = (string)$value;
        return in_array($visibility, ['private', 'shared', 'all'], true) ? $visibility : 'private';
    }

    /**
     * Rights granted to every authenticated Nextcloud user when visibility=all.
     * Read is implicit in visibility=all; the stored flags only describe extra
     * edit/delete/share capabilities. In other visibility modes they are reset.
     */
    private function normalizeAllPermissions(mixed $value, string $visibility): array {
        // CZ-FUNKCE: Normalizuje vstupní data do jednotného formátu. Vstup: `$value`, `$visibility`. Výstup: hodnota typu `array`.
        if ($visibility !== 'all') {
            return ['read' => false, 'edit' => false, 'delete' => false, 'share' => false];
        }
        if (!is_array($value)) {
            throw new \InvalidArgumentException($this->l10n->t('Invalid sharing data'));
        }
        return [
            'read' => true,
            'edit' => (bool)($value['edit'] ?? $value['canEdit'] ?? false),
            'delete' => (bool)($value['delete'] ?? $value['canDelete'] ?? false),
            'share' => (bool)($value['share'] ?? $value['canShare'] ?? false),
        ];
    }

    private function normalizeAcl(mixed $value): array {
        // CZ-FUNKCE: Normalizuje vstupní data do jednotného formátu. Vstup: `$value`. Výstup: hodnota typu `array`.
        if (!is_array($value)) {
            throw new \InvalidArgumentException($this->l10n->t('Invalid sharing data'));
        }

        $result = [];
        $seen = [];
        foreach ($value as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $type = ($entry['type'] ?? '') === 'group' ? 'group' : 'user';
            $id = trim((string)($entry['id'] ?? ''));
            if ($id === '' || mb_strlen($id) > 64) {
                continue;
            }
            $key = $type . ':' . $id;
            if (isset($seen[$key])) {
                continue;
            }

            if ($type === 'user' && $this->userManager->get($id) === null) {
                throw new \InvalidArgumentException($this->l10n->t('Selected user no longer exists: %s', [$id]));
            }
            if ($type === 'group' && $this->groupManager->get($id) === null) {
                throw new \InvalidArgumentException($this->l10n->t('Selected group no longer exists: %s', [$id]));
            }

            $canEdit = (bool)($entry['canEdit'] ?? false);
            $canDelete = (bool)($entry['canDelete'] ?? false);
            $canShare = (bool)($entry['canShare'] ?? false);
            $canRead = (bool)($entry['canRead'] ?? true) || $canEdit || $canDelete || $canShare;
            $seen[$key] = true;
            $result[] = [
                'type' => $type,
                'id' => $id,
                'canRead' => $canRead,
                'canEdit' => $canRead && $canEdit,
                'canDelete' => $canRead && $canDelete,
                'canShare' => $canRead && $canShare,
            ];
        }

        return $result;
    }

    private function normalizeContent(mixed $value): string {
        // CZ-FUNKCE: Normalizuje vstupní data do jednotného formátu. Vstup: `$value`. Výstup: hodnota typu `string`.
        $content = (string)$value;
        if (strlen($content) > self::MAX_CONTENT_BYTES) {
            throw new \InvalidArgumentException($this->l10n->t('Page content is too large'));
        }

        return str_replace("\0", '', $content);
    }

    private function normalizeSortOrder(mixed $value): int {
        // CZ-FUNKCE: Normalizuje vstupní data do jednotného formátu. Vstup: `$value`. Výstup: hodnota typu `int`.
        $sortOrder = (int)$value;
        return max(-1000000, min(1000000, $sortOrder));
    }

    private function assertUuid(string $id): void {
        // CZ-FUNKCE: Ověří požadovanou podmínku a při chybě operaci zastaví. Vstup: `$id`. Výstup: bez návratové hodnoty.
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $id)) {
            throw new \InvalidArgumentException($this->l10n->t('Invalid page identifier'));
        }
    }

    private function safeDownloadName(string $slug): string {
        // CZ-FUNKCE: Provádí dílčí operaci `safeDownloadName` v rámci Home Wiki. Vstup: `$slug`. Výstup: hodnota typu `string`.
        $name = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $slug) ?? 'page';
        return trim($name, '-.') !== '' ? trim($name, '-.') : 'page';
    }
}
