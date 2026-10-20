<?php

declare(strict_types=1);

namespace OCA\HcHomeWiki\Service;

use OCP\Files\Cache\IScanner;
use OCP\Files\Folder;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Lock\LockedException;
use OCP\Lock\ILockingProvider;

final class WikiStorageService {
    public const ROOT_FOLDER = 'HomeWiki';
    private const MAX_ATTACHMENT_BYTES = 50 * 1024 * 1024;

    public function __construct(
        private IRootFolder $rootFolder,
        private IL10N $l10n,
        private IUserManager $userManager,
        private IUserSession $userSession,
        private ILockingProvider $lockingProvider,
    ) {
        // CZ-FUNKCE: Inicializuje objekt a jeho závislosti. Vstup: `$rootFolder`, `$l10n`, `$userManager`, `$userSession`. Výstup: bez samostatné návratové hodnoty; výsledkem je inicializovaný objekt.
    }

    /** @var array<string,int> Nested operations in the same request share one lock. */
    private array $ownerMutationDepth = [];

    /**
     * Uzamkne všechny mutace jedné Wiki vlastníka proti souběžným změnám.
     * Vstup: `$uid` vlastníka a callback `$operation`, který se má provést pod exkluzivním zámkem.
     * Výstup: návratová hodnota callbacku; při obsazeném zámku vyhodí chybu 423.
     */
    public function withOwnerMutationLock(string $uid, callable $operation): mixed {
        if (isset($this->ownerMutationDepth[$uid])) {
            return $operation();
        }
        $key = 'hcwiki-owner:' . substr(hash('sha256', $uid), 0, 48);
        try {
            $this->lockingProvider->acquireLock($key, ILockingProvider::LOCK_EXCLUSIVE);
        } catch (LockedException $error) {
            throw new \RuntimeException($this->l10n->t('Wiki is busy. Try again.'), 423, $error);
        }
        $this->ownerMutationDepth[$uid] = 1;
        try {
            return $operation();
        } finally {
            unset($this->ownerMutationDepth[$uid]);
            $this->lockingProvider->releaseLock($key, ILockingProvider::LOCK_EXCLUSIVE);
        }
    }

    /**
     * Vytvoří stránku při obnově zálohy a nikdy nepřepíše již existující soubor.
     * Vstup: vlastník `$uid`, relativní cesta `$relativePath`, Markdown `$content` a `$metadata`.
     * Výstup: bez návratové hodnoty; při kolizi identifikátoru skončí chybou 409.
     */
    public function createImportedPage(string $uid, string $relativePath, string $content, array $metadata): void {
        $header = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_THROW_ON_ERROR);
        $content = '<!-- homewiki-meta: ' . $header . " -->\n" . $content;
        $this->withStorageOwnerContext($uid, function () use ($uid, $relativePath, $content): void {
            $pages = $this->ensureFolder($this->wikiRoot($uid), 'pages');
            $name = basename($relativePath);
            if ($pages->nodeExists($name)) {
                throw new \RuntimeException($this->l10n->t('A page identifier from the backup already exists'), 409);
            }
            $file = $pages->newFile($name, '');
            try {
                $file->putContent($content);
            } catch (\Throwable $error) {
                try { $file->delete(); } catch (\Throwable) {}
                throw $error;
            }
        });
    }

    /**
     * Při rollbacku smaže pouze soubory stránky vytvořené právě probíhajícím importem.
     * Vstup: vlastník `$uid`, identifikátor `$pageId` a cesta `$relativePath`.
     * Výstup: bez návratové hodnoty; nesouvisející stránky a data zůstávají zachována.
     */
    public function deleteImportedPage(string $uid, string $pageId, string $relativePath): void {
        $this->withStorageOwnerContext($uid, function () use ($uid, $pageId, $relativePath): void {
            $root = $this->wikiRoot($uid);
            foreach ([$relativePath, 'media/' . $this->mediaFolderName($pageId), 'attachments/' . $this->mediaFolderName($pageId)] as $path) {
                try {
                    $root->get($path)->delete();
                } catch (NotFoundException) {
                }
            }
        });
    }

    public function pagePath(string $id, string $slug): string {
        // CZ-FUNKCE: Provádí dílčí operaci `pagePath` v rámci Home Wiki. Vstup: `$id`, `$slug`. Výstup: hodnota typu `string`.
        return 'pages/' . $id . '-' . $this->safeName($slug) . '.md';
    }

    public function writePage(string $uid, string $relativePath, string $content, array $metadata = []): void {
        // CZ-FUNKCE: Provádí dílčí operaci `writePage` v rámci Home Wiki. Vstup: `$uid`, `$relativePath`, `$content`, `$metadata`. Výstup: bez návratové hodnoty.
        if ($metadata !== []) {
            $header = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_THROW_ON_ERROR);
            $content = '<!-- homewiki-meta: ' . $header . " -->\n" . $content;
        }
        $this->withStorageOwnerContext($uid, function () use ($uid, $relativePath, $content): void {
            $this->retryLocked(function () use ($uid, $relativePath, $content): void {
                $pages = $this->ensureFolder($this->wikiRoot($uid), 'pages');
                try {
                    $pages->get(basename($relativePath))->putContent($content);
                } catch (NotFoundException) {
                    $pages->newFile(basename($relativePath), $content);
                }
            });
        });
    }

    public function readPage(string $uid, string $relativePath): string {
        // CZ-FUNKCE: Načte požadovaná data. Vstup: `$uid`, `$relativePath`. Výstup: hodnota typu `string`.
        try {
            return $this->parseRawPage($this->readRawPage($uid, $relativePath))['content'];
        } catch (NotFoundException) {
            return '';
        }
    }

    public function readRawPage(string $uid, string $relativePath): string {
        // CZ-FUNKCE: Načte požadovaná data. Vstup: `$uid`, `$relativePath`. Výstup: hodnota typu `string`.
        return $this->retryLocked(fn(): string => (string)$this->wikiRoot($uid)->get($relativePath)->getContent());
    }

    private function retryLocked(callable $operation): mixed {
        // CZ-FUNKCE: Provádí dílčí operaci `retryLocked` v rámci Home Wiki. Vstup: `$operation`. Výstup: hodnota typu `mixed`.
        foreach ([100000, 250000, 500000, 1000000] as $delayMicroseconds) {
            try {
                return $operation();
            } catch (LockedException) {
                usleep($delayMicroseconds);
            }
        }
        return $operation();
    }


    /**
     * Re-scan the HomeWiki subtree after an application DB rollback that may
     * have reverted Nextcloud filecache rows while physical Files moves had
     * already happened. This is a recovery-only path, not normal operation.
     */
    public function rescanWikiStorage(string $uid): void {
        // CZ-FUNKCE: Provádí dílčí operaci `rescanWikiStorage` v rámci Home Wiki. Vstup: `$uid`. Výstup: bez návratové hodnoty.
        $this->withStorageOwnerContext($uid, function () use ($uid): void {
            $root = $this->wikiRoot($uid);
            $storage = $root->getStorage();
            $storage->getScanner()->scan(
                $root->getInternalPath(),
                IScanner::SCAN_RECURSIVE,
                IScanner::REUSE_NONE,
            );
        });
    }

    /**
     * Repair the specific orphan state produced when a failed delete moved a
     * Markdown file to .trash but the surrounding DB transaction rolled the
     * filecache entry back to the live path. Only restores when the physical
     * live file is absent and the matching physical trash file is present.
     */
    public function recoverInterruptedTrashMove(string $uid, string $livePath, string $pageId): bool {
        // CZ-FUNKCE: Provádí dílčí operaci `recoverInterruptedTrashMove` v rámci Home Wiki. Vstup: `$uid`, `$livePath`, `$pageId`. Výstup: hodnota typu `bool`.
        return $this->withStorageOwnerContext($uid, function () use ($uid, $livePath, $pageId): bool {
            $root = $this->wikiRoot($uid);
            $storage = $root->getStorage();
            $base = trim($root->getInternalPath(), '/');
            $prefix = $base !== '' ? $base . '/' : '';
            $liveInternal = $prefix . ltrim($livePath, '/');
            $trashPath = '.trash/pages/' . basename($livePath);
            $trashInternal = $prefix . $trashPath;

            if ($storage->file_exists($liveInternal) || !$storage->file_exists($trashInternal)) {
                return false;
            }

            $storage->getScanner()->scan(
                $root->getInternalPath(),
                IScanner::SCAN_RECURSIVE,
                IScanner::REUSE_NONE,
            );
            $this->restoreTrashedPage($uid, $trashPath, $livePath, $pageId);
            return true;
        });
    }

    /**
     * Reconcile uncommitted physical trash residue before a new delete.
     *
     * A failed delete from older builds could leave two physical copies for the
     * same DB-live page: the canonical live Markdown plus an unreferenced copy
     * under .trash/pages. The normal trash move must not fail forever on that
     * stale copy. Because the caller first verifies that no logical trash row
     * exists, the live DB page is authoritative here.
     *
     * If the live copy is missing but the uncommitted trash copy exists, move
     * it back. If both exist, discard only the stale trash copy. Media folders
     * are reconciled with the same rule. Returns true when any repair/cleanup
     * was performed.
     */
    public function reconcileUncommittedTrashResidue(string $uid, string $livePath, string $pageId): bool {
        // CZ-FUNKCE: Provádí dílčí operaci `reconcileUncommittedTrashResidue` v rámci Home Wiki. Vstup: `$uid`, `$livePath`, `$pageId`. Výstup: hodnota typu `bool`.
        return $this->withStorageOwnerContext($uid, function () use ($uid, $livePath, $pageId): bool {
            $root = $this->wikiRoot($uid);
            $storage = $root->getStorage();
            $base = trim($root->getInternalPath(), '/');
            $prefix = $base !== '' ? $base . '/' : '';
            $livePath = ltrim($livePath, '/');
            $trashPath = '.trash/pages/' . basename($livePath);
            $liveInternal = $prefix . $livePath;
            $trashInternal = $prefix . $trashPath;

            $pageLive = $storage->file_exists($liveInternal);
            $pageTrash = $storage->file_exists($trashInternal);
            $mediaStates = [];
            $attachmentStates = [];
            $needsRepair = (!$pageLive && $pageTrash) || ($pageLive && $pageTrash);

            foreach (array_unique([$this->mediaFolderName($pageId), $pageId]) as $folderName) {
                $mediaLivePath = 'media/' . $folderName;
                $mediaTrashPath = '.trash/media/' . $folderName;
                $mediaLive = $storage->file_exists($prefix . $mediaLivePath);
                $mediaTrash = $storage->file_exists($prefix . $mediaTrashPath);
                $mediaStates[$folderName] = [$mediaLive, $mediaTrash];
                $needsRepair = $needsRepair || ($mediaLive && $mediaTrash) || (!$mediaLive && $mediaTrash);
            }

            foreach (array_unique([$this->mediaFolderName($pageId), $pageId]) as $folderName) {
                $attachmentLivePath = 'attachments/' . $folderName;
                $attachmentTrashPath = '.trash/attachments/' . $folderName;
                $attachmentLive = $storage->file_exists($prefix . $attachmentLivePath);
                $attachmentTrash = $storage->file_exists($prefix . $attachmentTrashPath);
                $attachmentStates[$folderName] = [$attachmentLive, $attachmentTrash];
                $needsRepair = $needsRepair || ($attachmentLive && $attachmentTrash) || (!$attachmentLive && $attachmentTrash);
            }

            if (!$needsRepair) {
                return false;
            }

            // Synchronize Nextcloud's filecache with the physical state before
            // performing compensating Files API operations.
            $storage->getScanner()->scan(
                $root->getInternalPath(),
                IScanner::SCAN_RECURSIVE,
                IScanner::REUSE_NONE,
            );

            $pages = $this->ensureFolder($root, 'pages');
            $trash = $this->ensureFolder($root, '.trash');
            $trashPages = $this->ensureFolder($trash, 'pages');
            $media = $this->ensureFolder($root, 'media');
            $trashMedia = $this->ensureFolder($trash, 'media');
            $attachments = $this->ensureFolder($root, 'attachments');
            $trashAttachments = $this->ensureFolder($trash, 'attachments');
            $liveName = basename($livePath);
            $changed = false;

            if (!$pages->nodeExists($liveName) && $trashPages->nodeExists($liveName)) {
                $trashPages->get($liveName)->move($pages->getPath() . '/' . $liveName);
                $changed = true;
            } elseif ($pages->nodeExists($liveName) && $trashPages->nodeExists($liveName)) {
                // No logical trash row exists, so this physical copy belongs to
                // an aborted delete and is safe to replace with the current page.
                $trashPages->get($liveName)->delete();
                $changed = true;
            }

            foreach (array_keys($mediaStates) as $folderName) {
                $hasLive = $media->nodeExists($folderName);
                $hasTrash = $trashMedia->nodeExists($folderName);
                if (!$hasLive && $hasTrash) {
                    $trashMedia->get($folderName)->move($media->getPath() . '/' . $folderName);
                    $changed = true;
                } elseif ($hasLive && $hasTrash) {
                    $trashMedia->get($folderName)->delete();
                    $changed = true;
                }
            }

            foreach (array_keys($attachmentStates) as $folderName) {
                $hasLive = $attachments->nodeExists($folderName);
                $hasTrash = $trashAttachments->nodeExists($folderName);
                if (!$hasLive && $hasTrash) {
                    $trashAttachments->get($folderName)->move($attachments->getPath() . '/' . $folderName);
                    $changed = true;
                } elseif ($hasLive && $hasTrash) {
                    $trashAttachments->get($folderName)->delete();
                    $changed = true;
                }
            }

            return $changed;
        });
    }

    /** Readable Markdown files carry a JSON comment so they can rebuild database indexes. */
    public function listPageBackups(string $uid): array {
        // CZ-FUNKCE: Vrací seznam dat pro danou operaci. Vstup: `$uid`. Výstup: hodnota typu `array`.
        try {
            $folder = $this->wikiRoot($uid)->get('pages');
        } catch (NotFoundException) {
            return [];
        }
        if (!$folder instanceof Folder) return [];
        $result = [];
        foreach ($folder->getDirectoryListing() as $node) {
            if (!$node instanceof File || !str_ends_with($node->getName(), '.md')) continue;
            $parsed = $this->parseRawPage((string)$node->getContent());
            if ($parsed['metadata'] === null) continue;
            $result[] = [
                'path' => 'pages/' . $node->getName(),
                'metadata' => $parsed['metadata'],
                'content' => $parsed['content'],
            ];
        }
        return $result;
    }

    /** @return array{metadata:?array,content:string} */
    public function parseRawPage(string $raw): array {
        // CZ-FUNKCE: Zpracuje vstupní reprezentaci na interní data. Vstup: `$raw`. Výstup: hodnota typu `array`.
        if (!preg_match('/^<!-- homewiki-meta: (\{[^\r\n]*\}) -->\r?\n/', $raw, $match)) {
            return ['metadata' => null, 'content' => $raw];
        }
        $metadata = json_decode($match[1], true);
        if (!is_array($metadata) || ($metadata['format'] ?? null) !== 1) {
            return ['metadata' => null, 'content' => $raw];
        }
        return ['metadata' => $metadata, 'content' => substr($raw, strlen($match[0]))];
    }

    /**
     * Move one live page and its media folders into HomeWiki/.trash.
     * Returns the relative path of the trashed Markdown file.
     */
    public function trashPage(string $uid, string $relativePath, string $pageId): string {
        // CZ-FUNKCE: Provádí dílčí operaci `trashPage` v rámci Home Wiki. Vstup: `$uid`, `$relativePath`, `$pageId`. Výstup: hodnota typu `string`.
        return $this->withStorageOwnerContext($uid, function () use ($uid, $relativePath, $pageId): string {
            $root = $this->wikiRoot($uid);
            $trash = $this->ensureFolder($root, '.trash');
            $trashPages = $this->ensureFolder($trash, 'pages');
            $trashMedia = $this->ensureFolder($trash, 'media');
            $trashAttachments = $this->ensureFolder($trash, 'attachments');
            $fileName = basename($relativePath);
            $trashPath = '.trash/pages/' . $fileName;
            $movedMedia = [];
            $movedAttachments = [];
            $pageMoved = false;

            try {
                if ($trashPages->nodeExists($fileName)) {
                    throw new \RuntimeException($this->l10n->t('A deleted copy of this page already exists'));
                }
                $node = $root->get($relativePath);
                $node->move($trashPages->getPath() . '/' . $fileName);
                $pageMoved = true;

                foreach (array_unique([$this->mediaFolderName($pageId), $pageId]) as $folderName) {
                    try {
                        $mediaRoot = $root->get('media');
                    } catch (NotFoundException) {
                        break;
                    }
                    if (!$mediaRoot instanceof Folder || !$mediaRoot->nodeExists($folderName)) {
                        continue;
                    }
                    if ($trashMedia->nodeExists($folderName)) {
                        throw new \RuntimeException($this->l10n->t('Deleted media for this page already exists'));
                    }
                    $mediaRoot->get($folderName)->move($trashMedia->getPath() . '/' . $folderName);
                    $movedMedia[] = $folderName;
                }

                foreach (array_unique([$this->mediaFolderName($pageId), $pageId]) as $folderName) {
                    try {
                        $attachmentsRoot = $root->get('attachments');
                    } catch (NotFoundException) {
                        break;
                    }
                    if (!$attachmentsRoot instanceof Folder || !$attachmentsRoot->nodeExists($folderName)) {
                        continue;
                    }
                    if ($trashAttachments->nodeExists($folderName)) {
                        throw new \RuntimeException($this->l10n->t('Deleted attachments for this page already exist'));
                    }
                    $attachmentsRoot->get($folderName)->move($trashAttachments->getPath() . '/' . $folderName);
                    $movedAttachments[] = $folderName;
                }
            } catch (\Throwable $error) {
                // Roll back any physical moves completed before a later Files event failed.
                try {
                    $attachmentsRoot = $this->ensureFolder($root, 'attachments');
                    foreach (array_reverse($movedAttachments) as $folderName) {
                        if ($trashAttachments->nodeExists($folderName) && !$attachmentsRoot->nodeExists($folderName)) {
                            $trashAttachments->get($folderName)->move($attachmentsRoot->getPath() . '/' . $folderName);
                        }
                    }
                    $mediaRoot = $this->ensureFolder($root, 'media');
                    foreach (array_reverse($movedMedia) as $folderName) {
                        if ($trashMedia->nodeExists($folderName) && !$mediaRoot->nodeExists($folderName)) {
                            $trashMedia->get($folderName)->move($mediaRoot->getPath() . '/' . $folderName);
                        }
                    }
                    if ($pageMoved && $trashPages->nodeExists($fileName) && !$root->nodeExists($relativePath)) {
                        $trashPages->get($fileName)->move($root->getPath() . '/' . $relativePath);
                    }
                } catch (\Throwable) {
                    // Preserve the original storage error; the caller logs higher-level rollback failures.
                }
                throw $error;
            }

            return $trashPath;
        });
    }

    /** Restore one page plus media/attachments from HomeWiki/.trash to the live tree. */
    public function restoreTrashedPage(string $uid, string $trashPath, string $livePath, string $pageId): void {
        // CZ-FUNKCE: Obnoví dříve odstraněná nebo verzovaná data. Vstup: `$uid`, `$trashPath`, `$livePath`, `$pageId`. Výstup: bez návratové hodnoty.
        $this->withStorageOwnerContext($uid, function () use ($uid, $trashPath, $livePath, $pageId): void {
            $root = $this->wikiRoot($uid);
            $pages = $this->ensureFolder($root, 'pages');
            $trash = $this->ensureFolder($root, '.trash');
            $trashMedia = $this->ensureFolder($trash, 'media');
            $trashAttachments = $this->ensureFolder($trash, 'attachments');
            $liveName = basename($livePath);
            $trashName = basename($trashPath);
            $movedMedia = [];
            $movedAttachments = [];
            $pageMoved = false;

            if ($pages->nodeExists($liveName)) {
                throw new \RuntimeException($this->l10n->t('A live Markdown file with this page identifier already exists'));
            }

            try {
                $trashNode = $root->get($trashPath);
                $trashNode->move($pages->getPath() . '/' . $liveName);
                $pageMoved = true;

                $mediaRoot = $this->ensureFolder($root, 'media');
                foreach (array_unique([$this->mediaFolderName($pageId), $pageId]) as $folderName) {
                    if (!$trashMedia->nodeExists($folderName)) {
                        continue;
                    }
                    if ($mediaRoot->nodeExists($folderName)) {
                        throw new \RuntimeException($this->l10n->t('Live media for this page already exists'));
                    }
                    $trashMedia->get($folderName)->move($mediaRoot->getPath() . '/' . $folderName);
                    $movedMedia[] = $folderName;
                }

                $attachmentsRoot = $this->ensureFolder($root, 'attachments');
                foreach (array_unique([$this->mediaFolderName($pageId), $pageId]) as $folderName) {
                    if (!$trashAttachments->nodeExists($folderName)) {
                        continue;
                    }
                    if ($attachmentsRoot->nodeExists($folderName)) {
                        throw new \RuntimeException($this->l10n->t('Live attachments for this page already exist'));
                    }
                    $trashAttachments->get($folderName)->move($attachmentsRoot->getPath() . '/' . $folderName);
                    $movedAttachments[] = $folderName;
                }
            } catch (\Throwable $error) {
                try {
                    $attachmentsRoot = $this->ensureFolder($root, 'attachments');
                    foreach (array_reverse($movedAttachments) as $folderName) {
                        if ($attachmentsRoot->nodeExists($folderName) && !$trashAttachments->nodeExists($folderName)) {
                            $attachmentsRoot->get($folderName)->move($trashAttachments->getPath() . '/' . $folderName);
                        }
                    }
                    foreach (array_reverse($movedMedia) as $folderName) {
                        $mediaRoot = $this->ensureFolder($root, 'media');
                        if ($mediaRoot->nodeExists($folderName) && !$trashMedia->nodeExists($folderName)) {
                            $mediaRoot->get($folderName)->move($trashMedia->getPath() . '/' . $folderName);
                        }
                    }
                    if ($pageMoved && $pages->nodeExists($liveName)) {
                        $trashPages = $this->ensureFolder($trash, 'pages');
                        if (!$trashPages->nodeExists($trashName)) {
                            $pages->get($liveName)->move($trashPages->getPath() . '/' . $trashName);
                        }
                    }
                } catch (\Throwable) {
                }
                throw $error;
            }
        });
    }

    /** Permanently remove the physical page, media and attachments already located in .trash. */
    public function deleteTrashedPage(string $uid, string $trashPath, string $pageId): void {
        // CZ-FUNKCE: Smaže požadovaná data. Vstup: `$uid`, `$trashPath`, `$pageId`. Výstup: bez návratové hodnoty.
        $this->withStorageOwnerContext($uid, function () use ($uid, $trashPath, $pageId): void {
            $root = $this->wikiRoot($uid);
            try {
                $root->get($trashPath)->delete();
            } catch (NotFoundException) {
            }
            foreach (array_unique([$this->mediaFolderName($pageId), $pageId]) as $folderName) {
                try {
                    $root->get('.trash/media/' . $folderName)->delete();
                } catch (NotFoundException) {
                }
                try {
                    $root->get('.trash/attachments/' . $folderName)->delete();
                } catch (NotFoundException) {
                }
            }
        });
    }

    public function deletePage(string $uid, string $relativePath): void {
        // CZ-FUNKCE: Smaže požadovaná data. Vstup: `$uid`, `$relativePath`. Výstup: bez návratové hodnoty.
        $this->withStorageOwnerContext($uid, function () use ($uid, $relativePath): void {
            $root = $this->wikiRoot($uid);
            try {
                $root->get($relativePath)->delete();
            } catch (NotFoundException) {
            }
        });
    }

    public function renamePage(string $uid, string $oldPath, string $newPath): void {
        // CZ-FUNKCE: Přejmenuje požadovaný objekt. Vstup: `$uid`, `$oldPath`, `$newPath`. Výstup: bez návratové hodnoty.
        if ($oldPath === $newPath) {
            return;
        }

        $this->withStorageOwnerContext($uid, function () use ($uid, $oldPath, $newPath): void {
            $root = $this->wikiRoot($uid);
            try {
                $node = $root->get($oldPath);
                $node->move($root->getPath() . '/' . $newPath);
            } catch (NotFoundException) {
            }
        });
    }

    public function storeMedia(
        string $uid,
        string $pageId,
        string $originalName,
        string $tmpPath,
        int $size,
    ): array {
        // CZ-FUNKCE: Uloží data pro další použití. Vstup: `$uid`, `$pageId`, `$originalName`, `$tmpPath`, `$size`. Výstup: hodnota typu `array`.
        if ($size <= 0 || $size > 10 * 1024 * 1024) {
            throw new \InvalidArgumentException($this->l10n->t('Image must be smaller than 10 MB'));
        }

        $mime = $this->detectMimeType($tmpPath);
        $allowedMimeTypes = [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
        ];
        if (!in_array($mime, $allowedMimeTypes, true)) {
            throw new \InvalidArgumentException($this->l10n->t('Only image files are allowed'));
        }

        return $this->withStorageOwnerContext($uid, function () use ($uid, $pageId, $originalName, $tmpPath): array {
            $root = $this->wikiRoot($uid);
            $media = $this->ensureFolder($root, 'media');
            // Nextcloud's recording-upload key contains the folder name plus a 40-character hash.
            // The full UUID makes that key exceed the appconfig key limit (64 characters).
            $folderName = $this->mediaFolderName($pageId);
            $pageFolder = $this->ensureFolder($media, $folderName);
            try {
                $legacyFolder = $media->get($pageId);
            } catch (NotFoundException) {
                $legacyFolder = null;
            }
            $name = $this->uniqueName($pageFolder, $this->safeUploadName($originalName), $legacyFolder instanceof Folder ? $legacyFolder : null);
            $stream = fopen($tmpPath, 'rb');
            if ($stream === false) {
                throw new \RuntimeException($this->l10n->t('Cannot open uploaded file'));
            }

            $file = null;
            try {
                $file = $pageFolder->newFile($name);
                $file->putContent($stream);
            } catch (\Throwable $error) {
                if ($file instanceof File) {
                    try {
                        $file->delete();
                    } catch (\Throwable) {
                        // Preserve the upload error when cleanup also fails.
                    }
                }
                throw $error;
            } finally {
                // putContent() can consume and close the supplied stream itself.
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            return [
                'name' => $name,
                'path' => 'media/' . $folderName . '/' . $name,
                'markdown' => '![' . $this->escapeAlt(pathinfo($name, PATHINFO_FILENAME)) . '](../media/' . $folderName . '/' . rawurlencode($name) . ')',
            ];
        });
    }

    /** Store an arbitrary attachment belonging to one page. */
    public function storeAttachment(
        string $uid,
        string $pageId,
        string $originalName,
        string $tmpPath,
        int $size,
    ): array {
        // CZ-FUNKCE: Uloží data pro další použití. Vstup: `$uid`, `$pageId`, `$originalName`, `$tmpPath`, `$size`. Výstup: hodnota typu `array`.
        if ($size <= 0 || $size > self::MAX_ATTACHMENT_BYTES) {
            throw new \InvalidArgumentException($this->l10n->t('Attachment must be smaller than 50 MB'));
        }

        $mime = $this->detectMimeType($tmpPath);
        return $this->withStorageOwnerContext($uid, function () use ($uid, $pageId, $originalName, $tmpPath, $size, $mime): array {
            $root = $this->wikiRoot($uid);
            $attachments = $this->ensureFolder($root, 'attachments');
            $folderName = $this->mediaFolderName($pageId);
            $pageFolder = $this->ensureFolder($attachments, $folderName);
            try {
                $legacyFolder = $attachments->get($pageId);
            } catch (NotFoundException) {
                $legacyFolder = null;
            }
            $safeName = $this->safeUploadName($originalName);
            if (str_starts_with($safeName, '.')) {
                $safeName = 'file' . $safeName;
            }
            $name = $this->uniqueName($pageFolder, $safeName, $legacyFolder instanceof Folder ? $legacyFolder : null);
            $stream = fopen($tmpPath, 'rb');
            if ($stream === false) {
                throw new \RuntimeException($this->l10n->t('Cannot open uploaded file'));
            }
            $file = null;
            try {
                $file = $pageFolder->newFile($name);
                $file->putContent($stream);
            } catch (\Throwable $error) {
                if ($file instanceof File) {
                    try { $file->delete(); } catch (\Throwable) {}
                }
                throw $error;
            } finally {
                if (is_resource($stream)) fclose($stream);
            }
            return [
                'name' => $name,
                'size' => $size,
                'mime' => $mime !== '' ? $mime : 'application/octet-stream',
                'modified' => time(),
            ];
        });
    }

    /** @return list<array{name:string,size:int,mime:string,modified:int}> */
    public function listAttachmentFiles(string $uid, string $pageId): array {
        // CZ-FUNKCE: Vrací seznam dat pro danou operaci. Vstup: `$uid`, `$pageId`. Výstup: hodnota typu `array`.
        return $this->withStorageOwnerContext($uid, function () use ($uid, $pageId): array {
            $root = $this->wikiRoot($uid);
            $result = [];
            $seen = [];
            foreach ([$this->mediaFolderName($pageId), $pageId] as $folderName) {
                try {
                    $folder = $root->get('attachments/' . $folderName);
                } catch (NotFoundException) {
                    continue;
                }
                if (!$folder instanceof Folder) continue;
                foreach ($folder->getDirectoryListing() as $node) {
                    if (!$node instanceof File) continue;
                    $name = $node->getName();
                    if (!$this->isSafeAttachmentName($name) || isset($seen[$name])) continue;
                    $size = (int)$node->getSize();
                    if ($size < 0 || $size > self::MAX_ATTACHMENT_BYTES) continue;
                    $mime = (string)$node->getMimeType();
                    $seen[$name] = true;
                    $result[] = [
                        'name' => $name,
                        'size' => $size,
                        'mime' => $mime !== '' ? $mime : 'application/octet-stream',
                        'modified' => (int)$node->getMTime(),
                    ];
                }
            }
            usort($result, static fn(array $a, array $b): int => strnatcasecmp((string)$a['name'], (string)$b['name']));
            return $result;
        });
    }

    /** Read an attachment for forced download. */
    public function readAttachment(string $uid, string $pageId, string $name): ?array {
        // CZ-FUNKCE: Načte požadovaná data. Vstup: `$uid`, `$pageId`, `$name`. Výstup: hodnota typu `?array`.
        if (!$this->isSafeAttachmentName($name)) return null;
        return $this->withStorageOwnerContext($uid, function () use ($uid, $pageId, $name): ?array {
            $root = $this->wikiRoot($uid);
            $node = null;
            foreach ([$this->mediaFolderName($pageId), $pageId] as $folderName) {
                try {
                    $candidate = $root->get('attachments/' . $folderName . '/' . $name);
                    if ($candidate instanceof File) { $node = $candidate; break; }
                } catch (NotFoundException) {}
            }
            if (!$node instanceof File) return null;
            $size = (int)$node->getSize();
            if ($size < 0 || $size > self::MAX_ATTACHMENT_BYTES) return null;
            return [
                'name' => $name,
                'content' => (string)$node->getContent(),
                'size' => $size,
                'mime' => ((string)$node->getMimeType()) ?: 'application/octet-stream',
            ];
        });
    }

    public function deleteAttachment(string $uid, string $pageId, string $name): bool {
        // CZ-FUNKCE: Smaže požadovaná data. Vstup: `$uid`, `$pageId`, `$name`. Výstup: hodnota typu `bool`.
        if (!$this->isSafeAttachmentName($name)) return false;
        return $this->withStorageOwnerContext($uid, function () use ($uid, $pageId, $name): bool {
            $root = $this->wikiRoot($uid);
            foreach ([$this->mediaFolderName($pageId), $pageId] as $folderName) {
                try {
                    $node = $root->get('attachments/' . $folderName . '/' . $name);
                } catch (NotFoundException) {
                    continue;
                }
                if ($node instanceof File) {
                    $node->delete();
                    return true;
                }
            }
            return false;
        });
    }

    public function storeImportedAttachmentContent(string $uid, string $pageId, string $name, string $content): void {
        // CZ-FUNKCE: Uloží data pro další použití. Vstup: `$uid`, `$pageId`, `$name`, `$content`. Výstup: bez návratové hodnoty.
        if (!$this->isSafeAttachmentName($name)) {
            throw new \InvalidArgumentException($this->l10n->t('Invalid attachment filename'));
        }
        if ($content === '' || strlen($content) > self::MAX_ATTACHMENT_BYTES) {
            throw new \InvalidArgumentException($this->l10n->t('Attachment must be smaller than 50 MB'));
        }
        $this->withStorageOwnerContext($uid, function () use ($uid, $pageId, $name, $content): void {
            $attachments = $this->ensureFolder($this->wikiRoot($uid), 'attachments');
            $folder = $this->ensureFolder($attachments, $this->mediaFolderName($pageId));
            if ($folder->nodeExists($name)) {
                throw new \RuntimeException($this->l10n->t('Imported attachment already exists'));
            }
            $folder->newFile($name, $content);
        });
    }

    /** Only raster images belonging to the requested page may be displayed. */
    public function readMedia(string $uid, string $pageId, string $name): ?array {
        // CZ-FUNKCE: Načte požadovaná data. Vstup: `$uid`, `$pageId`, `$name`. Výstup: hodnota typu `?array`.
        if ($name === '' || $name[0] === '.' || basename($name) !== $name || preg_match('/[\\x00-\\x1f\\x7f]/', $name)) {
            return null;
        }
        try {
            $node = $this->wikiRoot($uid)->get('media/' . $pageId . '/' . $name);
        } catch (NotFoundException) {
            try {
                $node = $this->wikiRoot($uid)->get('media/' . $this->mediaFolderName($pageId) . '/' . $name);
            } catch (NotFoundException) {
                return null;
            }
        }
        if (!$node instanceof File || $node->getSize() > 10 * 1024 * 1024) {
            return null;
        }
        $content = (string)$node->getContent();
        $mime = (string)(new \finfo(FILEINFO_MIME_TYPE))->buffer($content);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
            return null;
        }
        return ['content' => $content, 'mime' => $mime];
    }

    /**
     * Vrátí seznam obrázků stránky bez načítání jejich obsahu do paměti.
     * Vstup: vlastník `$uid` a identifikátor stránky `$pageId`.
     * Výstup: seznam bezpečných názvů a velikostí médií pro následné postupné čtení.
     *
     * @return list<array{name:string,size:int}>
     */
    public function listMediaFiles(string $uid, string $pageId): array {
        return $this->withStorageOwnerContext($uid, function () use ($uid, $pageId): array {
            $root = $this->wikiRoot($uid);
            $result = [];
            $seen = [];
            foreach ([$this->mediaFolderName($pageId), $pageId] as $folderName) {
                try {
                    $folder = $root->get('media/' . $folderName);
                } catch (NotFoundException) {
                    continue;
                }
                if (!$folder instanceof Folder) {
                    continue;
                }
                foreach ($folder->getDirectoryListing() as $node) {
                    if (!$node instanceof File) {
                        continue;
                    }
                    $name = $node->getName();
                    $size = (int)$node->getSize();
                    if (isset($seen[$name]) || $size < 0 || $size > 10 * 1024 * 1024) {
                        continue;
                    }
                    if ($name === '' || $name[0] === '.' || basename($name) !== $name || preg_match('/[\x00-\x1f\x7f]/', $name)) {
                        continue;
                    }
                    $seen[$name] = true;
                    $result[] = ['name' => $name, 'size' => $size];
                }
            }
            return $result;
        });
    }

    /** Restore one exported page image under the canonical media folder. */
    public function storeImportedMediaContent(string $uid, string $pageId, string $name, string $content): void {
        // CZ-FUNKCE: Uloží data pro další použití. Vstup: `$uid`, `$pageId`, `$name`, `$content`. Výstup: bez návratové hodnoty.
        if ($name === '' || $name[0] === '.' || basename($name) !== $name || preg_match('/[\\x00-\\x1f\\x7f]/', $name)) {
            throw new \InvalidArgumentException($this->l10n->t('Invalid media filename'));
        }
        if ($content === '' || strlen($content) > 10 * 1024 * 1024) {
            throw new \InvalidArgumentException($this->l10n->t('Image must be smaller than 10 MB'));
        }
        $mime = (string)(new \finfo(FILEINFO_MIME_TYPE))->buffer($content);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
            throw new \InvalidArgumentException($this->l10n->t('Only image files are allowed'));
        }

        $this->withStorageOwnerContext($uid, function () use ($uid, $pageId, $name, $content): void {
            $media = $this->ensureFolder($this->wikiRoot($uid), 'media');
            $folder = $this->ensureFolder($media, $this->mediaFolderName($pageId));
            if ($folder->nodeExists($name)) {
                throw new \RuntimeException($this->l10n->t('Imported media already exists'));
            }
            $folder->newFile($name, $content);
        });
    }

    /**
     * True when HomeWiki contains user/application data. The root-level
     * .nomedia marker is internal infrastructure used only to keep the
     * Preview Generator/OnlyOffice preview pipeline away from Home Wiki's
     * internal Markdown files and therefore does not make an empty Wiki non-empty.
     */
    public function wikiHasFiles(string $uid): bool {
        // CZ-FUNKCE: Provádí dílčí operaci `wikiHasFiles` v rámci Home Wiki. Vstup: `$uid`. Výstup: hodnota typu `bool`.
        return $this->withStorageOwnerContext($uid, function () use ($uid): bool {
            $userFolder = $this->rootFolder->getUserFolder($uid);
            try {
                $root = $userFolder->get(self::ROOT_FOLDER);
            } catch (NotFoundException) {
                return false;
            }
            if (!$root instanceof Folder) {
                return true;
            }
            $stack = [$root];
            while ($stack !== []) {
                $folder = array_pop($stack);
                foreach ($folder->getDirectoryListing() as $node) {
                    if ($node instanceof File) {
                        if ($node->getName() === '.nomedia') {
                            continue;
                        }
                        return true;
                    }
                    if ($node instanceof Folder) {
                        $stack[] = $node;
                    }
                }
            }
            return false;
        });
    }

    /** Remove the complete HomeWiki Files folder for the selected owner. */
    public function deleteWikiRoot(string $uid): void {
        // CZ-FUNKCE: Smaže požadovaná data. Vstup: `$uid`. Výstup: bez návratové hodnoty.
        $this->withStorageOwnerContext($uid, function () use ($uid): void {
            $userFolder = $this->rootFolder->getUserFolder($uid);
            try {
                $userFolder->get(self::ROOT_FOLDER)->delete();
            } catch (NotFoundException) {
            }
        });
    }

    /**
     * Move HomeWiki aside before a destructive whole-Wiki delete. The caller can
     * restore the staged folder when the database transaction fails.
     */
    public function stageWikiRootForDeletion(string $uid): ?string {
        // CZ-FUNKCE: Provádí dílčí operaci `stageWikiRootForDeletion` v rámci Home Wiki. Vstup: `$uid`. Výstup: hodnota typu `?string`.
        return $this->withStorageOwnerContext($uid, function () use ($uid): ?string {
            $userFolder = $this->rootFolder->getUserFolder($uid);
            try {
                $root = $userFolder->get(self::ROOT_FOLDER);
            } catch (NotFoundException) {
                return null;
            }
            $name = '.HomeWiki-delete-' . bin2hex(random_bytes(8));
            $root->move($userFolder->getPath() . '/' . $name);
            return $name;
        });
    }

    public function restoreStagedWikiRoot(string $uid, ?string $stagedName): void {
        // CZ-FUNKCE: Obnoví dříve odstraněná nebo verzovaná data. Vstup: `$uid`, `$stagedName`. Výstup: bez návratové hodnoty.
        if ($stagedName === null || $stagedName === '') {
            return;
        }
        $this->withStorageOwnerContext($uid, function () use ($uid, $stagedName): void {
            $userFolder = $this->rootFolder->getUserFolder($uid);
            if ($userFolder->nodeExists(self::ROOT_FOLDER)) {
                throw new \RuntimeException($this->l10n->t('HomeWiki folder already exists'));
            }
            $userFolder->get($stagedName)->move($userFolder->getPath() . '/' . self::ROOT_FOLDER);
        });
    }

    public function purgeStagedWikiRoot(string $uid, ?string $stagedName): void {
        // CZ-FUNKCE: Provádí dílčí operaci `purgeStagedWikiRoot` v rámci Home Wiki. Vstup: `$uid`, `$stagedName`. Výstup: bez návratové hodnoty.
        if ($stagedName === null || $stagedName === '') {
            return;
        }
        $this->withStorageOwnerContext($uid, function () use ($uid, $stagedName): void {
            $userFolder = $this->rootFolder->getUserFolder($uid);
            try {
                $userFolder->get($stagedName)->delete();
            } catch (NotFoundException) {
            }
        });
    }

    /**
     * Home Wiki authorizes delegated writes in its own ACL, while the physical
     * Markdown file remains inside the page owner's Nextcloud Files tree.
     *
     * Nextcloud file event listeners (notably files_versions) validate the
     * active session user against the physical source node. A shared Wiki
     * editor therefore needs the filesystem event phase to run in the owner's
     * request-scoped context; otherwise files_versions reports
     * "Version file not accessible by current user" even though Home Wiki
     * already authorized the edit.
     *
     * setVolatileActiveUser() changes only the active user for this request.
     * The original actor is always restored in finally and the persistent
     * login session is never modified. Authorization must happen before this
     * helper is entered.
     */
    private function withStorageOwnerContext(string $ownerUid, callable $operation): mixed {
        // CZ-FUNKCE: Provádí dílčí operaci `withStorageOwnerContext` v rámci Home Wiki. Vstup: `$ownerUid`, `$operation`. Výstup: hodnota typu `mixed`.
        $activeUser = $this->userSession->getUser();
        if ($activeUser?->getUID() === $ownerUid) {
            return $operation();
        }

        $owner = $this->userManager->get($ownerUid);
        if ($owner === null) {
            throw new \RuntimeException($this->l10n->t('Page owner no longer exists'));
        }

        $this->userSession->setVolatileActiveUser($owner);
        try {
            return $operation();
        } finally {
            $this->userSession->setVolatileActiveUser($activeUser);
        }
    }

    private function wikiRoot(string $uid): Folder {
        // CZ-FUNKCE: Provádí dílčí operaci `wikiRoot` v rámci Home Wiki. Vstup: `$uid`. Výstup: hodnota typu `Folder`.
        $userFolder = $this->rootFolder->getUserFolder($uid);
        try {
            $node = $userFolder->get(self::ROOT_FOLDER);
            if (!$node instanceof Folder) {
                throw new \RuntimeException($this->l10n->t('HomeWiki exists but is not a folder'));
            }
            $root = $node;
        } catch (NotFoundException) {
            $root = $userFolder->newFolder(self::ROOT_FOLDER);
        }

        $this->ensurePreviewGeneratorOptOut($root);
        return $root;
    }

    /**
     * Preview Generator listens to Files write events and can enqueue our
     * internal Markdown pages for generic previews. OnlyOffice may then be
     * selected as a Markdown preview provider. A Wiki page can legitimately be
     * moved to HomeWiki/.trash before that asynchronous queue item runs, which
     * produces noisy "file not found" errors even though Home Wiki itself does
     * not need those previews. Preview Generator officially treats an empty
     * .nomedia file as an opt-out for the containing subtree.
     *
     * This is best-effort infrastructure only: inability to create the marker
     * must never make the Wiki itself unavailable.
     */
    private function ensurePreviewGeneratorOptOut(Folder $root): void {
        // CZ-FUNKCE: Zajistí požadovaný stav nebo strukturu. Vstup: `$root`. Výstup: bez návratové hodnoty.
        try {
            if (!$root->nodeExists('.nomedia')) {
                $root->newFile('.nomedia', '');
            }
        } catch (\Throwable) {
            // Non-critical. Home Wiki works without background-preview opt-out.
        }
    }

    private function ensureFolder(Folder $parent, string $name): Folder {
        // CZ-FUNKCE: Zajistí požadovaný stav nebo strukturu. Vstup: `$parent`, `$name`. Výstup: hodnota typu `Folder`.
        try {
            $node = $parent->get($name);
            if ($node instanceof Folder) {
                return $node;
            }
            throw new \RuntimeException($this->l10n->t('%s exists but is not a folder', [$name]));
        } catch (NotFoundException) {
            return $parent->newFolder($name);
        }
    }

    private function mediaFolderName(string $pageId): string {
        // CZ-FUNKCE: Provádí dílčí operaci `mediaFolderName` v rámci Home Wiki. Vstup: `$pageId`. Výstup: hodnota typu `string`.
        return substr(str_replace('-', '', strtolower($pageId)), 0, 12);
    }

    private function uniqueName(Folder $folder, string $name, ?Folder $legacyFolder = null): string {
        // CZ-FUNKCE: Provádí dílčí operaci `uniqueName` v rámci Home Wiki. Vstup: `$folder`, `$name`, `$legacyFolder`. Výstup: hodnota typu `string`.
        if (!$folder->nodeExists($name) && !($legacyFolder?->nodeExists($name) ?? false)) {
            return $name;
        }

        $pathInfo = pathinfo($name);
        $base = $pathInfo['filename'] ?? 'file';
        $extension = isset($pathInfo['extension']) ? '.' . $pathInfo['extension'] : '';
        for ($index = 2; $index < 1000; $index++) {
            $candidate = $base . '-' . $index . $extension;
            if (!$folder->nodeExists($candidate) && !($legacyFolder?->nodeExists($candidate) ?? false)) {
                return $candidate;
            }
        }

        return bin2hex(random_bytes(6)) . $extension;
    }

    private function safeName(string $value): string {
        // CZ-FUNKCE: Provádí dílčí operaci `safeName` v rámci Home Wiki. Vstup: `$value`. Výstup: hodnota typu `string`.
        $value = strtolower(trim($value));
        $value = preg_replace('~[^a-z0-9._-]+~u', '-', $value) ?? 'page';
        $value = trim($value, '-.');

        return $value !== '' ? mb_substr($value, 0, 120) : 'page';
    }

    private function safeUploadName(string $value): string {
        // CZ-FUNKCE: Provádí dílčí operaci `safeUploadName` v rámci Home Wiki. Vstup: `$value`. Výstup: hodnota typu `string`.
        $value = basename(str_replace('\\', '/', $value));
        $value = preg_replace('/[^\pL\pN._ -]+/u', '_', $value) ?? 'file';

        return trim($value) !== '' ? mb_substr($value, 0, 180) : 'file';
    }

    private function isSafeAttachmentName(string $name): bool {
        // CZ-FUNKCE: Vyhodnotí logickou podmínku. Vstup: `$name`. Výstup: hodnota typu `bool`.
        return $name !== '' && $name[0] !== '.' && basename($name) === $name && !preg_match('/[\x00-\x1f\x7f]/', $name);
    }

    private function detectMimeType(string $tmpPath): string {
        // CZ-FUNKCE: Provádí dílčí operaci `detectMimeType` v rámci Home Wiki. Vstup: `$tmpPath`. Výstup: hodnota typu `string`.
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        return (string)$finfo->file($tmpPath);
    }

    private function escapeAlt(string $value): string {
        // CZ-FUNKCE: Provádí dílčí operaci `escapeAlt` v rámci Home Wiki. Vstup: `$value`. Výstup: hodnota typu `string`.
        return str_replace(['[', ']'], ['', ''], $value);
    }
}
