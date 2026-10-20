<?php

declare(strict_types=1);

namespace OCA\HcHomeWiki\Service;

use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IUserManager;
use OCP\IL10N;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * Optional bridge to Nextcloud's bundled files_versions app.
 *
 * Home Wiki deliberately does not persist a second copy of page history. The
 * canonical Markdown file already goes through the Nextcloud Files layer, so
 * files_versions creates and expires revisions according to the instance-wide
 * retention policy. This service only exposes those revisions through the Wiki
 * UI. If files_versions is disabled, Home Wiki itself keeps working normally.
 */
final class WikiVersionService {
    private const VERSION_MANAGER = 'OCA\\Files_Versions\\Versions\\IVersionManager';
    private const MAX_LISTED_VERSIONS = 200;

    public function __construct(
        private IRootFolder $rootFolder,
        private IUserManager $userManager,
        private WikiStorageService $storage,
        private IL10N $l10n,
        private LoggerInterface $logger,
    ) {
        // CZ-FUNKCE: Inicializuje objekt a jeho závislosti. Vstup: `$rootFolder`, `$userManager`, `$storage`, `$l10n`, `$logger`. Výstup: bez samostatné návratové hodnoty; výsledkem je inicializovaný objekt.
    }

    /** @return array{available:bool,versions:list<array{id:string,timestamp:int,size:int,fileName:string,mimeType:string}>} */
    public function listForPage(array $page): array {
        // CZ-FUNKCE: Vrací seznam dat pro danou operaci. Vstup: `$page`. Výstup: hodnota typu `array`.
        $manager = $this->versionManager();
        if ($manager === null) {
            return ['available' => false, 'versions' => []];
        }

        [$owner, $file] = $this->source($page);
        try {
            $versions = $manager->getVersionsForFile($owner, $file);
        } catch (\Throwable $exception) {
            $this->logger->warning('Home Wiki could not list file versions.', [
                'exception' => $exception,
                'pageId' => (string)($page['id'] ?? ''),
            ]);
            return ['available' => false, 'versions' => []];
        }

        // Nextcloud 35's LegacyVersionsBackend intentionally prepends the
        // *current* source file to getVersionsForFile(). That descriptor has
        // revisionId == current source mtime, but there is no matching
        // files_versions/*.v<revision> payload for it. Calling read() on this
        // synthetic current descriptor therefore raises NotFoundException.
        // Home Wiki shows historical versions only; the current page is already
        // open in the editor, so filter that descriptor out here.
        $currentRevisionId = (string)$file->getMtime();
        $result = [];
        foreach ($versions as $version) {
            if (count($result) >= self::MAX_LISTED_VERSIONS) {
                break;
            }
            try {
                $revisionId = (string)$version->getRevisionId();
                if ($revisionId === $currentRevisionId) {
                    continue;
                }
                $result[] = [
                    'id' => $revisionId,
                    'timestamp' => (int)$version->getTimestamp(),
                    'size' => max(0, (int)$version->getSize()),
                    'fileName' => (string)$version->getSourceFileName(),
                    'mimeType' => (string)$version->getMimeType(),
                ];
            } catch (\Throwable $exception) {
                $this->logger->debug('Home Wiki skipped an unreadable version descriptor.', [
                    'exception' => $exception,
                    'pageId' => (string)($page['id'] ?? ''),
                ]);
            }
        }

        usort($result, static fn(array $a, array $b): int => $b['timestamp'] <=> $a['timestamp']);
        return ['available' => true, 'versions' => $result];
    }

    /** @return array{id:string,timestamp:int,size:int,fileName:string,mimeType:string,content:string}|null */
    public function readForPage(array $page, string $versionId): ?array {
        // CZ-FUNKCE: Načte požadovaná data. Vstup: `$page`, `$versionId`. Výstup: hodnota typu `?array`.
        $versionId = trim($versionId);
        if ($versionId === '' || strlen($versionId) > 128) {
            return null;
        }

        $manager = $this->versionManager();
        if ($manager === null) {
            throw new \RuntimeException($this->l10n->t('Version history is unavailable'), 503);
        }

        [$owner, $file] = $this->source($page);
        // The current source revision is a synthetic descriptor in Nextcloud
        // 35, not a stored historical .v file. A stale/open dialog may still
        // request it, so reject it as a non-history item before read().
        if ($versionId === (string)$file->getMtime()) {
            return null;
        }
        try {
            $versions = $manager->getVersionsForFile($owner, $file);
        } catch (\Throwable $exception) {
            throw new \RuntimeException($this->l10n->t('Version history is unavailable'), 503, $exception);
        }

        foreach ($versions as $version) {
            if ((string)$version->getRevisionId() !== $versionId) {
                continue;
            }

            try {
                $stream = $manager->read($version);
            } catch (NotFoundException $exception) {
                // Version descriptors can become stale when retention/pruning
                // races with an already-open history dialog. Treat that as a
                // vanished history item, never as an application HTTP 500.
                $this->logger->debug('Home Wiki version payload disappeared before it could be read.', [
                    'exception' => $exception,
                    'pageId' => (string)($page['id'] ?? ''),
                    'versionId' => $versionId,
                ]);
                return null;
            } catch (\Throwable $exception) {
                $this->logger->warning('Home Wiki could not read a file version.', [
                    'exception' => $exception,
                    'pageId' => (string)($page['id'] ?? ''),
                    'versionId' => $versionId,
                ]);
                throw new \RuntimeException($this->l10n->t('Could not read page version'), 503, $exception);
            }
            if (!is_resource($stream)) {
                throw new \RuntimeException($this->l10n->t('Could not read page version'), 503);
            }
            try {
                $raw = stream_get_contents($stream);
            } finally {
                fclose($stream);
            }
            if (!is_string($raw)) {
                throw new \RuntimeException($this->l10n->t('Could not read page version'), 503);
            }
            $parsed = $this->storage->parseRawPage($raw);

            return [
                'id' => $versionId,
                'timestamp' => (int)$version->getTimestamp(),
                'size' => max(0, (int)$version->getSize()),
                'fileName' => (string)$version->getSourceFileName(),
                'mimeType' => (string)$version->getMimeType(),
                'content' => (string)$parsed['content'],
            ];
        }

        return null;
    }

    /** @return array{0:\OCP\IUser,1:File} */
    private function source(array $page): array {
        // CZ-FUNKCE: Vrátí zdrojová data pro daný objekt. Vstup: `$page`. Výstup: hodnota typu `array`.
        $ownerUid = (string)($page['owner_uid'] ?? '');
        $relativePath = (string)($page['file_path'] ?? '');
        $owner = $ownerUid !== '' ? $this->userManager->get($ownerUid) : null;
        if ($owner === null) {
            throw new \RuntimeException($this->l10n->t('Page owner no longer exists'), 404);
        }

        try {
            $node = $this->rootFolder->getUserFolder($ownerUid)->get(
                WikiStorageService::ROOT_FOLDER . '/' . ltrim($relativePath, '/'),
            );
        } catch (NotFoundException $exception) {
            throw new \RuntimeException($this->l10n->t('Page not found'), 404, $exception);
        }
        if (!$node instanceof File) {
            throw new \RuntimeException($this->l10n->t('Page not found'), 404);
        }

        return [$owner, $node];
    }

    private function versionManager(): ?object {
        // CZ-FUNKCE: Vrátí správce verzí pro práci s historií souboru. Vstup: žádný přímý parametr. Výstup: hodnota typu `?object`.
        $class = self::VERSION_MANAGER;
        if (!interface_exists($class)) {
            return null;
        }
        try {
            $manager = Server::get($class);
            return is_object($manager) ? $manager : null;
        } catch (\Throwable $exception) {
            $this->logger->debug('Home Wiki files_versions bridge is unavailable.', ['exception' => $exception]);
            return null;
        }
    }
}
