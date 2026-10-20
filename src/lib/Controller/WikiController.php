<?php

declare(strict_types=1);

namespace OCA\HcHomeWiki\Controller;

use OCA\HcHomeWiki\AppInfo\Application;
use OCA\HcHomeWiki\Http\TemporaryStreamResponse;
use OCA\HcHomeWiki\Service\WikiService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\Lock\LockedException;
use OCP\IUserSession;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

final class WikiController extends Controller {
    public function __construct(
        IRequest $request,
        private IUserSession $userSession,
        private WikiService $wikiService,
        private IL10N $l10n,
        private LoggerInterface $logger,
    ) {
        // CZ-FUNKCE: Inicializuje objekt a jeho závislosti. Vstup: `$request`, `$userSession`, `$wikiService`, `$l10n`, `$logger`. Výstup: bez samostatné návratové hodnoty; výsledkem je inicializovaný objekt.
        parent::__construct(Application::APP_ID, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function listPages(): JSONResponse {
        // CZ-FUNKCE: Vrací seznam dat pro danou operaci. Vstup: žádný přímý parametr. Výstup: hodnota typu `JSONResponse`.
        return new JSONResponse(['pages' => $this->wikiService->listPages($this->uid())]);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function recoveryPreview(): JSONResponse {
        // CZ-FUNKCE: Provádí dílčí operaci `recoveryPreview` v rámci Home Wiki. Vstup: žádný přímý parametr. Výstup: hodnota typu `JSONResponse`.
        return new JSONResponse($this->wikiService->recoverPages($this->uid(), false));
    }

    #[NoAdminRequired]
    public function recoverPages(): JSONResponse {
        // CZ-FUNKCE: Provádí dílčí operaci `recoverPages` v rámci Home Wiki. Vstup: žádný přímý parametr. Výstup: hodnota typu `JSONResponse`.
        return new JSONResponse($this->wikiService->recoverPages($this->uid(), true));
    }

    #[NoAdminRequired]
    public function snapshotPages(): JSONResponse {
        // CZ-FUNKCE: Provádí dílčí operaci `snapshotPages` v rámci Home Wiki. Vstup: žádný přímý parametr. Výstup: hodnota typu `JSONResponse`.
        return new JSONResponse(['updated' => $this->wikiService->snapshotPages($this->uid())]);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getPage(string $id): JSONResponse {
        // CZ-FUNKCE: Načte a vrátí požadovaná data. Vstup: `$id`. Výstup: hodnota typu `JSONResponse`.
        try {
            $page = $this->wikiService->getPage($this->uid(), $id);
            return $page === null
                ? new JSONResponse(['error' => $this->l10n->t('Page not found')], 404)
                : new JSONResponse($page);
        } catch (\InvalidArgumentException $exception) {
            return new JSONResponse(['error' => $exception->getMessage()], 400);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getPageState(string $id): JSONResponse {
        // CZ-FUNKCE: Načte a vrátí požadovaná data. Vstup: `$id`. Výstup: hodnota typu `JSONResponse`.
        try {
            $state = $this->wikiService->getPageState($this->uid(), $id);
            return $state === null
                ? new JSONResponse(['error' => $this->l10n->t('Page not found')], 404)
                : new JSONResponse($state);
        } catch (\InvalidArgumentException $exception) {
            return new JSONResponse(['error' => $exception->getMessage()], 400);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function listRecentPages(): JSONResponse {
        // CZ-FUNKCE: Vrací seznam dat pro danou operaci. Vstup: žádný přímý parametr. Výstup: hodnota typu `JSONResponse`.
        return new JSONResponse($this->wikiService->listRecentOverview($this->uid()));
    }

    #[NoAdminRequired]
    public function recordPageVisit(string $id): JSONResponse {
        // CZ-FUNKCE: Zapíše událost nebo statistický údaj. Vstup: `$id`. Výstup: hodnota typu `JSONResponse`.
        try {
            return new JSONResponse($this->wikiService->recordPageVisit($this->uid(), $id));
        } catch (\InvalidArgumentException $exception) {
            return new JSONResponse(['error' => $exception->getMessage()], 400);
        } catch (\RuntimeException $exception) {
            $status = $exception->getCode() === 404 ? 404 : 500;
            return new JSONResponse(['error' => $exception->getMessage()], $status);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function listPageVersions(string $id): JSONResponse {
        // CZ-FUNKCE: Vrací seznam dat pro danou operaci. Vstup: `$id`. Výstup: hodnota typu `JSONResponse`.
        try {
            return new JSONResponse($this->wikiService->listPageVersions($this->uid(), $id));
        } catch (\InvalidArgumentException $exception) {
            return new JSONResponse(['error' => $exception->getMessage()], 400);
        } catch (\RuntimeException $exception) {
            $status = in_array($exception->getCode(), [403, 404, 503], true) ? $exception->getCode() : 500;
            return new JSONResponse(['error' => $exception->getMessage()], $status);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getPageVersion(string $id, string $version): JSONResponse {
        // CZ-FUNKCE: Načte a vrátí požadovaná data. Vstup: `$id`, `$version`. Výstup: hodnota typu `JSONResponse`.
        try {
            return new JSONResponse($this->wikiService->getPageVersion($this->uid(), $id, $version));
        } catch (\InvalidArgumentException $exception) {
            return new JSONResponse(['error' => $exception->getMessage()], 400);
        } catch (\RuntimeException $exception) {
            $status = in_array($exception->getCode(), [403, 404, 503], true) ? $exception->getCode() : 500;
            return new JSONResponse(['error' => $exception->getMessage()], $status);
        }
    }

    #[NoAdminRequired]
    public function restorePageVersion(string $id, string $version): JSONResponse {
        // CZ-FUNKCE: Obnoví dříve odstraněná nebo verzovaná data. Vstup: `$id`, `$version`. Výstup: hodnota typu `JSONResponse`.
        try {
            $body = $this->jsonBody();
            return new JSONResponse(['page' => $this->wikiService->restorePageVersion(
                $this->uid(),
                $id,
                $version,
                (int)($body['expectedRevision'] ?? 0),
            )]);
        } catch (\InvalidArgumentException $exception) {
            return new JSONResponse(['error' => $exception->getMessage()], 400);
        } catch (LockedException) {
            return new JSONResponse(['error' => $this->l10n->t('Page is temporarily locked. Try saving again.')], 423);
        } catch (\RuntimeException $exception) {
            $status = in_array($exception->getCode(), [403, 404, 409, 503], true) ? $exception->getCode() : 500;
            return new JSONResponse(['error' => $exception->getMessage()], $status);
        }
    }

    #[NoAdminRequired]
    public function createPage(): JSONResponse {
        // CZ-FUNKCE: Vytvoří nový záznam nebo objekt. Vstup: žádný přímý parametr. Výstup: hodnota typu `JSONResponse`.
        try {
            $page = $this->wikiService->createPage($this->uid(), $this->jsonBody());
            return new JSONResponse($page, 201);
        } catch (\InvalidArgumentException $exception) {
            return new JSONResponse(['error' => $exception->getMessage()], 400);
        } catch (\Throwable $exception) {
            $this->logger->error('Home Wiki create API failed.', ['exception' => $exception]);
            return new JSONResponse(['error' => $this->l10n->t('Could not create page')], 500);
        }
    }

    #[NoAdminRequired]
    public function updatePage(string $id): JSONResponse {
        // CZ-FUNKCE: Aktualizuje existující data. Vstup: `$id`. Výstup: hodnota typu `JSONResponse`.
        try {
            $page = $this->wikiService->updatePage($this->uid(), $id, $this->jsonBody());
            return $page === null
                ? new JSONResponse(['error' => $this->l10n->t('Page not found')], 404)
                : new JSONResponse($page);
        } catch (\InvalidArgumentException $exception) {
            return new JSONResponse(['error' => $exception->getMessage()], 400);
        } catch (LockedException) {
            return new JSONResponse(['error' => $this->l10n->t('Page is temporarily locked. Try saving again.')], 423);
        } catch (\RuntimeException $exception) {
            $status = in_array($exception->getCode(), [403, 404, 409], true) ? $exception->getCode() : 500;
            return new JSONResponse(['error' => $exception->getMessage()], $status);
        } catch (\Throwable $exception) {
            $this->logger->error('Home Wiki update API failed.', ['exception' => $exception]);
            return new JSONResponse(['error' => $this->l10n->t('Could not save page')], 500);
        }
    }

    #[NoAdminRequired]
    public function deletePage(string $id): JSONResponse {
        // CZ-FUNKCE: Smaže požadovaná data. Vstup: `$id`. Výstup: hodnota typu `JSONResponse`.
        try {
            $body = $this->jsonBody();
            $this->wikiService->deletePage(
                $this->uid(),
                $id,
                (int)($body['expectedRevision'] ?? 0),
                (string)($body['scope'] ?? 'single'),
            );
            return new JSONResponse(['ok' => true]);
        } catch (\InvalidArgumentException $exception) {
            return new JSONResponse(['error' => $exception->getMessage()], 400);
        } catch (\RuntimeException $exception) {
            $status = in_array($exception->getCode(), [403, 404, 409], true) ? $exception->getCode() : 500;
            return new JSONResponse(['error' => $exception->getMessage()], $status);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function listTrash(): JSONResponse {
        // CZ-FUNKCE: Vrací seznam dat pro danou operaci. Vstup: žádný přímý parametr. Výstup: hodnota typu `JSONResponse`.
        return new JSONResponse(['pages' => $this->wikiService->listTrash($this->uid())]);
    }

    #[NoAdminRequired]
    public function emptyTrash(): JSONResponse {
        // CZ-FUNKCE: Provádí dílčí operaci `emptyTrash` v rámci Home Wiki. Vstup: žádný přímý parametr. Výstup: hodnota typu `JSONResponse`.
        try {
            return new JSONResponse($this->wikiService->emptyTrash($this->uid()));
        } catch (\RuntimeException $exception) {
            return new JSONResponse(['error' => $exception->getMessage()], 500);
        }
    }

    #[NoAdminRequired]
    public function restoreTrash(string $id): JSONResponse {
        // CZ-FUNKCE: Obnoví dříve odstraněná nebo verzovaná data. Vstup: `$id`. Výstup: hodnota typu `JSONResponse`.
        try {
            return new JSONResponse(['page' => $this->wikiService->restoreTrash($this->uid(), $id)]);
        } catch (\InvalidArgumentException $exception) {
            return new JSONResponse(['error' => $exception->getMessage()], 400);
        } catch (\RuntimeException $exception) {
            $status = in_array($exception->getCode(), [403, 404, 409], true) ? $exception->getCode() : 500;
            return new JSONResponse(['error' => $exception->getMessage()], $status);
        }
    }

    #[NoAdminRequired]
    public function permanentlyDeleteTrash(string $id): JSONResponse {
        // CZ-FUNKCE: Provádí dílčí operaci `permanentlyDeleteTrash` v rámci Home Wiki. Vstup: `$id`. Výstup: hodnota typu `JSONResponse`.
        try {
            $this->wikiService->permanentlyDeleteTrash($this->uid(), $id);
            return new JSONResponse(['ok' => true]);
        } catch (\InvalidArgumentException $exception) {
            return new JSONResponse(['error' => $exception->getMessage()], 400);
        } catch (\RuntimeException $exception) {
            $status = in_array($exception->getCode(), [403, 404], true) ? $exception->getCode() : 500;
            return new JSONResponse(['error' => $exception->getMessage()], $status);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function resolveAlias(string $alias = '', string $ownerUid = ''): JSONResponse {
        // CZ-FUNKCE: Vyřeší a vrátí odpovídající hodnotu. Vstup: `$alias`, `$ownerUid`. Výstup: hodnota typu `JSONResponse`.
        $page = $this->wikiService->resolveAlias($this->uid(), $ownerUid, $alias);
        return $page === null
            ? new JSONResponse(['error' => $this->l10n->t('Page not found')], 404)
            : new JSONResponse(['page' => $page]);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getSettings(): JSONResponse {
        // CZ-FUNKCE: Načte a vrátí požadovaná data. Vstup: žádný přímý parametr. Výstup: hodnota typu `JSONResponse`.
        return new JSONResponse($this->wikiService->getStartupSettings($this->uid()));
    }

    #[NoAdminRequired]
    public function updateSettings(): JSONResponse {
        // CZ-FUNKCE: Aktualizuje existující data. Vstup: žádný přímý parametr. Výstup: hodnota typu `JSONResponse`.
        try {
            return new JSONResponse($this->wikiService->updateStartupSettings($this->uid(), $this->jsonBody()));
        } catch (\InvalidArgumentException $exception) {
            return new JSONResponse(['error' => $exception->getMessage()], 400);
        } catch (\RuntimeException $exception) {
            $status = $exception->getCode() === 403 ? 403 : 500;
            return new JSONResponse(['error' => $exception->getMessage()], $status);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function search(string $q = ''): JSONResponse {
        // CZ-FUNKCE: Vyhledá odpovídající data. Vstup: `$q`. Výstup: hodnota typu `JSONResponse`.
        return new JSONResponse(['pages' => $this->wikiService->search($this->uid(), $q)]);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function backlinks(string $id): JSONResponse {
        // CZ-FUNKCE: Provádí dílčí operaci `backlinks` v rámci Home Wiki. Vstup: `$id`. Výstup: hodnota typu `JSONResponse`.
        try {
            return new JSONResponse(['pages' => $this->wikiService->backlinks($this->uid(), $id)]);
        } catch (\InvalidArgumentException $exception) {
            return new JSONResponse(['error' => $exception->getMessage()], 400);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function dashboard(): JSONResponse {
        // CZ-FUNKCE: Provádí dílčí operaci `dashboard` v rámci Home Wiki. Vstup: žádný přímý parametr. Výstup: hodnota typu `JSONResponse`.
        return new JSONResponse(['pages' => $this->wikiService->dashboard($this->uid())]);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function exportWiki(): Response {
        // CZ-FUNKCE: Připraví export požadovaných dat. Vstup: žádný přímý parametr. Výstup: hodnota typu `Response`.
        try {
            $export = $this->wikiService->exportWiki($this->uid());
            return new TemporaryStreamResponse(
                $export['path'],
                200,
                [
                    'Content-Type' => 'application/zip',
                    'Content-Disposition' => 'attachment; filename="' . $export['filename'] . '"',
                    'Content-Length' => (string)$export['size'],
                    'Cache-Control' => 'no-store',
                ],
            );
        } catch (\RuntimeException $exception) {
            $status = in_array($exception->getCode(), [409, 503], true) ? $exception->getCode() : 500;
            return new JSONResponse(['error' => $exception->getMessage()], $status);
        }
    }

    #[NoAdminRequired]
    public function importWiki(): JSONResponse {
        // CZ-FUNKCE: Načte a zpracuje importovaná data. Vstup: žádný přímý parametr. Výstup: hodnota typu `JSONResponse`.
        try {
            $uploadedFile = $this->request->getUploadedFile('file');
            if (!is_array($uploadedFile)) {
                return new JSONResponse(['error' => $this->l10n->t('Upload failed')], 400);
            }
            return new JSONResponse($this->wikiService->importWiki($this->uid(), $uploadedFile), 201);
        } catch (\InvalidArgumentException $exception) {
            return new JSONResponse(['error' => $exception->getMessage()], 400);
        } catch (\RuntimeException $exception) {
            $status = in_array($exception->getCode(), [409, 503], true) ? $exception->getCode() : 500;
            return new JSONResponse(['error' => $exception->getMessage()], $status);
        } catch (\Throwable $exception) {
            $this->logger->error('Home Wiki whole-Wiki import failed.', ['exception' => $exception]);
            return new JSONResponse(['error' => $this->l10n->t('Could not import Wiki')], 500);
        }
    }

    #[NoAdminRequired]
    #[PasswordConfirmationRequired(strict: true)]
    public function deleteWholeWiki(): JSONResponse {
        // CZ-FUNKCE: Smaže požadovaná data. Vstup: žádný přímý parametr. Výstup: hodnota typu `JSONResponse`.
        try {
            return new JSONResponse($this->wikiService->deleteWholeWiki($this->uid()));
        } catch (\Throwable $exception) {
            $this->logger->error('Home Wiki whole-Wiki delete failed.', ['exception' => $exception]);
            return new JSONResponse(['error' => $this->l10n->t('Could not delete Wiki')], 500);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function exportPage(string $id): Response {
        // CZ-FUNKCE: Připraví export požadovaných dat. Vstup: `$id`. Výstup: hodnota typu `Response`.
        try {
            $export = $this->wikiService->exportPage($this->uid(), $id);
            if ($export === null) {
                return new JSONResponse(['error' => $this->l10n->t('Page not found')], 404);
            }

            return new DataDownloadResponse(
                $export['content'],
                $export['filename'],
                'text/markdown; charset=utf-8',
            );
        } catch (\InvalidArgumentException $exception) {
            return new JSONResponse(['error' => $exception->getMessage()], 400);
        }
    }

    #[NoAdminRequired]
    public function upload(string $id): JSONResponse {
        // CZ-FUNKCE: Provádí dílčí operaci `upload` v rámci Home Wiki. Vstup: `$id`. Výstup: hodnota typu `JSONResponse`.
        try {
            $uploadedFile = $this->request->getUploadedFile('file');
            if (!is_array($uploadedFile)) {
                return new JSONResponse(['error' => $this->l10n->t('Upload failed')], 400);
            }

            $result = $this->wikiService->uploadImage($this->uid(), $id, $uploadedFile);
            return new JSONResponse($result, 201);
        } catch (\InvalidArgumentException $exception) {
            return new JSONResponse(['error' => $exception->getMessage()], 400);
        } catch (\RuntimeException $exception) {
            $status = in_array($exception->getCode(), [403, 404], true) ? $exception->getCode() : 500;
            return new JSONResponse(['error' => $exception->getMessage()], $status);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function displayMedia(string $id, string $name): Response {
        // CZ-FUNKCE: Provádí dílčí operaci `displayMedia` v rámci Home Wiki. Vstup: `$id`, `$name`. Výstup: hodnota typu `Response`.
        try {
            $image = $this->wikiService->displayMedia($this->uid(), $id, $name);
            if ($image === null) {
                return new JSONResponse(['error' => $this->l10n->t('Page not found')], 404);
            }
            return new DataDisplayResponse($image['content'], 200, [
                'Content-Type' => $image['mime'],
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store',
            ]);
        } catch (\InvalidArgumentException) {
            return new JSONResponse(['error' => $this->l10n->t('Page not found')], 404);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function listAttachments(string $id): JSONResponse {
        // CZ-FUNKCE: Vrací seznam dat pro danou operaci. Vstup: `$id`. Výstup: hodnota typu `JSONResponse`.
        try {
            return new JSONResponse($this->wikiService->listAttachments($this->uid(), $id));
        } catch (\InvalidArgumentException $exception) {
            return new JSONResponse(['error' => $exception->getMessage()], 400);
        } catch (\RuntimeException $exception) {
            $status = in_array($exception->getCode(), [403, 404], true) ? $exception->getCode() : 500;
            return new JSONResponse(['error' => $exception->getMessage()], $status);
        }
    }

    #[NoAdminRequired]
    public function uploadAttachment(string $id): JSONResponse {
        // CZ-FUNKCE: Provádí dílčí operaci `uploadAttachment` v rámci Home Wiki. Vstup: `$id`. Výstup: hodnota typu `JSONResponse`.
        try {
            $uploadedFile = $this->request->getUploadedFile('file');
            if (!is_array($uploadedFile)) {
                return new JSONResponse(['error' => $this->l10n->t('Upload failed')], 400);
            }
            return new JSONResponse($this->wikiService->uploadAttachment($this->uid(), $id, $uploadedFile), 201);
        } catch (\InvalidArgumentException $exception) {
            return new JSONResponse(['error' => $exception->getMessage()], 400);
        } catch (\RuntimeException $exception) {
            $status = in_array($exception->getCode(), [403, 404], true) ? $exception->getCode() : 500;
            return new JSONResponse(['error' => $exception->getMessage()], $status);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function downloadAttachment(string $id, string $name): Response {
        // CZ-FUNKCE: Provádí dílčí operaci `downloadAttachment` v rámci Home Wiki. Vstup: `$id`, `$name`. Výstup: hodnota typu `Response`.
        try {
            $attachment = $this->wikiService->downloadAttachment($this->uid(), $id, $name);
            if ($attachment === null) {
                return new JSONResponse(['error' => $this->l10n->t('Attachment not found')], 404);
            }
            return new DataDownloadResponse(
                (string)$attachment['content'],
                (string)$attachment['name'],
                'application/octet-stream',
            );
        } catch (\InvalidArgumentException) {
            return new JSONResponse(['error' => $this->l10n->t('Attachment not found')], 404);
        }
    }

    #[NoAdminRequired]
    public function deleteAttachment(string $id, string $name): JSONResponse {
        // CZ-FUNKCE: Smaže požadovaná data. Vstup: `$id`, `$name`. Výstup: hodnota typu `JSONResponse`.
        try {
            $deleted = $this->wikiService->deleteAttachment($this->uid(), $id, $name);
            return $deleted
                ? new JSONResponse(['deleted' => true])
                : new JSONResponse(['error' => $this->l10n->t('Attachment not found')], 404);
        } catch (\InvalidArgumentException $exception) {
            return new JSONResponse(['error' => $exception->getMessage()], 400);
        } catch (\RuntimeException $exception) {
            $status = in_array($exception->getCode(), [403, 404], true) ? $exception->getCode() : 500;
            return new JSONResponse(['error' => $exception->getMessage()], $status);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function listTags(): JSONResponse {
        // CZ-FUNKCE: Vrací seznam dat pro danou operaci. Vstup: žádný přímý parametr. Výstup: hodnota typu `JSONResponse`.
        return new JSONResponse($this->wikiService->listTags($this->uid()));
    }

    #[NoAdminRequired]
    public function createTag(): JSONResponse {
        // CZ-FUNKCE: Vytvoří nový záznam nebo objekt. Vstup: žádný přímý parametr. Výstup: hodnota typu `JSONResponse`.
        try {
            return new JSONResponse(['tag' => $this->wikiService->createTag($this->uid(), $this->jsonBody())], 201);
        } catch (\InvalidArgumentException $exception) {
            return new JSONResponse(['error' => $exception->getMessage()], 400);
        } catch (\RuntimeException $exception) {
            $status = in_array($exception->getCode(), [403, 404, 409], true) ? $exception->getCode() : 500;
            return new JSONResponse(['error' => $exception->getMessage()], $status);
        }
    }

    #[NoAdminRequired]
    public function renameTag(string $id): JSONResponse {
        // CZ-FUNKCE: Přejmenuje požadovaný objekt. Vstup: `$id`. Výstup: hodnota typu `JSONResponse`.
        try {
            return new JSONResponse($this->wikiService->renameTag($this->uid(), $id, $this->jsonBody()));
        } catch (\InvalidArgumentException $exception) {
            return new JSONResponse(['error' => $exception->getMessage()], 400);
        } catch (\RuntimeException $exception) {
            $status = in_array($exception->getCode(), [403, 404, 409], true) ? $exception->getCode() : 500;
            return new JSONResponse(['error' => $exception->getMessage()], $status);
        }
    }

    #[NoAdminRequired]
    public function deleteTag(string $id): JSONResponse {
        // CZ-FUNKCE: Smaže požadovaná data. Vstup: `$id`. Výstup: hodnota typu `JSONResponse`.
        try {
            return new JSONResponse($this->wikiService->deleteTag($this->uid(), $id));
        } catch (\InvalidArgumentException $exception) {
            return new JSONResponse(['error' => $exception->getMessage()], 400);
        } catch (\RuntimeException $exception) {
            $status = in_array($exception->getCode(), [403, 404], true) ? $exception->getCode() : 500;
            return new JSONResponse(['error' => $exception->getMessage()], $status);
        }
    }

    #[NoAdminRequired]
    public function assignTag(string $id): JSONResponse {
        // CZ-FUNKCE: Provádí dílčí operaci `assignTag` v rámci Home Wiki. Vstup: `$id`. Výstup: hodnota typu `JSONResponse`.
        try {
            return new JSONResponse($this->wikiService->assignTag($this->uid(), $id, $this->jsonBody()));
        } catch (\InvalidArgumentException $exception) {
            return new JSONResponse(['error' => $exception->getMessage()], 400);
        } catch (\RuntimeException $exception) {
            $status = in_array($exception->getCode(), [403, 404, 409], true) ? $exception->getCode() : 500;
            return new JSONResponse(['error' => $exception->getMessage()], $status);
        }
    }

    #[NoAdminRequired]
    public function removeTag(string $id, string $tagId): JSONResponse {
        // CZ-FUNKCE: Odstraní požadovaná data nebo vazbu. Vstup: `$id`, `$tagId`. Výstup: hodnota typu `JSONResponse`.
        try {
            $scope = (string)$this->request->getParam('scope', 'single');
            return new JSONResponse($this->wikiService->removeTag($this->uid(), $id, $tagId, $scope));
        } catch (\InvalidArgumentException $exception) {
            return new JSONResponse(['error' => $exception->getMessage()], 400);
        } catch (\RuntimeException $exception) {
            $status = in_array($exception->getCode(), [403, 404, 409], true) ? $exception->getCode() : 500;
            return new JSONResponse(['error' => $exception->getMessage()], $status);
        }
    }

    private function uid(): string {
        // CZ-FUNKCE: Provádí dílčí operaci `uid` v rámci Home Wiki. Vstup: žádný přímý parametr. Výstup: hodnota typu `string`.
        $uid = $this->userSession->getUser()?->getUID();
        if ($uid === null || $uid === '') {
            throw new \RuntimeException($this->l10n->t('Not authenticated'), 401);
        }
        return $uid;
    }

    private function jsonBody(): array {
        // CZ-FUNKCE: Provádí dílčí operaci `jsonBody` v rámci Home Wiki. Vstup: žádný přímý parametr. Výstup: hodnota typu `array`.
        // Nextcloud decodes application/json into the public IRequest parameter
        // collection. Request::getContent() is protected in Nextcloud 35.
        return $this->request->getParams();
    }
}
