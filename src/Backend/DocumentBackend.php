<?php

declare(strict_types=1);

namespace Alnv\GenaiBundle\Backend;

use Alnv\GenaiBundle\Vectors\ChromaDb;
use Alnv\GenaiBundle\Vectors\DocumentLockedException;
use Alnv\GenaiBundle\Vectors\SyncIncompleteException;
use Contao\Backend;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\Database;
use Contao\DataContainer;
use Contao\Input;
use Contao\Message;
use Contao\System;
use Psr\Log\LogLevel;
use Symfony\Component\Security\Csrf\CsrfToken;

/**
 * Backend actions and DCA callbacks that keep tl_genai_documents and the vector DB in sync.
 *
 * - key=syncDocument   : embed exactly one document now
 * - key=removeVectors  : remove one document from the vector DB, keep the record (synced_at=0)
 * - ondelete_callback  : delete the vectors before Contao deletes the record
 * - onundo_callback    : restored records are re-synced
 */
class DocumentBackend extends Backend
{
    /**
     * key=syncDocument
     */
    public function syncDocument(DataContainer $dc): void
    {
        $id = $this->getValidatedDocumentId();
        $lang = $GLOBALS['TL_LANG']['tl_genai_documents'] ?? [];

        // Large documents: embed what fits into ~60s, the cronjob continues with the rest
        @\set_time_limit(180);

        try {
            $chroma = $this->getChroma();
            // Manual sync re-enables a document that was removed from the vector DB before
            $result = $chroma->syncDocument($id, true, \microtime(true) + 60);

            if ($result['synced']) {
                Message::addConfirmation(\sprintf($lang['syncSuccess'] ?? 'Document ID %d synchronized (%d vectors).', $id, $result['chunks']));
            } else {
                Message::addInfo(\sprintf($lang['syncChanged'] ?? 'Document ID %d changed during the synchronization and stays queued.', $id));
            }
        } catch (SyncIncompleteException $e) {
            Message::addInfo(\sprintf($lang['syncPartial'] ?? 'Document ID %d: %d of %d chunks embedded, the cronjob continues with the rest.', $id, $e->done, $e->total));
        } catch (DocumentLockedException $e) {
            Message::addInfo(\sprintf($lang['syncLocked'] ?? 'Document ID %d is currently being synchronized.', $id));
        } catch (\Throwable $e) {
            $this->log("Manual sync of document {$id} failed: {$e->getMessage()}", LogLevel::ERROR);
            Message::addError(\sprintf($lang['syncError'] ?? 'Document ID %d could not be synchronized: %s', $id, $e->getMessage()));
        }

        $this->redirect($this->getReferer());
    }

    /**
     * key=removeVectors
     */
    public function removeVectors(DataContainer $dc): void
    {
        $id = $this->getValidatedDocumentId();
        $lang = $GLOBALS['TL_LANG']['tl_genai_documents'] ?? [];

        try {
            $removed = $this->getChroma()->removeDocument($id);
            Message::addConfirmation(\sprintf($lang['removeSuccess'] ?? 'Document ID %d removed from the vector database (%d vectors).', $id, $removed));
        } catch (DocumentLockedException $e) {
            Message::addInfo(\sprintf($lang['syncLocked'] ?? 'Document ID %d is currently being synchronized.', $id));
        } catch (\Throwable $e) {
            $this->log("Removing document {$id} from the vector DB failed: {$e->getMessage()}", LogLevel::ERROR);
            Message::addError(\sprintf($lang['removeError'] ?? 'Document ID %d could not be removed from the vector database: %s', $id, $e->getMessage()));
        }

        $this->redirect($this->getReferer());
    }

    /**
     * ondelete_callback tl_genai_documents.
     *
     * Contao calls it after writing tl_undo and before the DELETE. If the vectors cannot be
     * removed, the deletion is aborted (no orphaned embeddings) and the undo entry is removed.
     */
    public function onDeleteDocument(DataContainer $dc, int $undoId): void
    {
        $id = (int) $dc->id;

        try {
            $chroma = ChromaDb::fromConfig();

            if ($chroma === null) {
                return; // GenAI not configured -> nothing to clean up
            }

            $chroma->deleteByDocumentId($id);
        } catch (\Throwable $e) {
            $this->abortDeletion($undoId, $id, $e);
        }
    }

    /**
     * onundo_callback tl_genai_documents: the vectors were deleted -> embed again.
     */
    public function onUndoDocument(string $table, array $row): void
    {
        Database::getInstance()
            ->prepare("UPDATE tl_genai_documents SET synced_at=0, sync_error='', sync_failed_at=0 WHERE id=?")
            ->execute((int) $row['id']);
    }

    /**
     * ondelete_callback tl_genai_documents_collections. Contao deletes the child documents
     * without calling their ondelete_callback, so the vectors are removed here.
     */
    public function onDeleteCollection(DataContainer $dc, int $undoId): void
    {
        $id = (int) $dc->id;

        try {
            $chroma = ChromaDb::fromConfig();

            if ($chroma === null) {
                return;
            }

            $documentIds = Database::getInstance()
                ->prepare('SELECT id FROM tl_genai_documents WHERE pid=?')
                ->execute($id)
                ->fetchEach('id');

            $chroma->deleteByDocumentIds($documentIds);
            $chroma->deleteByCollectionId($id);
        } catch (\Throwable $e) {
            $this->abortDeletion($undoId, $id, $e);
        }
    }

    /**
     * Label: title + sync state
     */
    public function renderDocumentRow(array $row): string
    {
        $lang = $GLOBALS['TL_LANG']['tl_genai_documents'] ?? [];
        $badge = static fn (string $text, string $color, string $title = '') => \sprintf(
            '<span style="font-size:10px;padding:2px 7px;color:#fff;background:%s;border-radius:10px;margin-left:5px" title="%s">%s</span>',
            $color,
            self::specialcharsValue($title),
            self::specialcharsValue($text)
        );

        $html = self::specialcharsValue((string) ($row['title'] ?? '-'));

        if (!empty($row['sync_disabled'])) {
            $html .= $badge($lang['stateDisabled'] ?? 'not in vector DB', '#6b7280');
        } elseif ((int) $row['synced_at'] > 0) {
            $html .= $badge(\date($GLOBALS['TL_CONFIG']['datimFormat'] ?? 'd.m.Y H:i', (int) $row['synced_at']), 'green');
        } else {
            $html .= $badge($lang['statePending'] ?? 'pending', '#d97706');
        }

        if (!empty($row['sync_error'])) {
            $html .= $badge($lang['stateError'] ?? 'error', '#dc2626', (string) $row['sync_error']);
        }

        return $html;
    }

    /**
     * The id must exist, and the request token (rt) of the backend link must be valid,
     * because these GET actions change data.
     */
    private function getValidatedDocumentId(): int
    {
        $container = System::getContainer();
        $token = (string) Input::get('rt');

        if (!$container->get('contao.csrf.token_manager')->isTokenValid(new CsrfToken($container->getParameter('contao.csrf_token_name'), $token))) {
            Message::addError($GLOBALS['TL_LANG']['ERR']['invalidRequestToken'] ?? 'Invalid request token.');
            $this->redirect($this->getReferer());
        }

        $id = (int) Input::get('id');

        if ($id < 1 || !Database::getInstance()->prepare('SELECT id FROM tl_genai_documents WHERE id=?')->execute($id)->numRows) {
            Message::addError(\sprintf($GLOBALS['TL_LANG']['ERR']['invalidId'] ?? 'Invalid ID "%s".', $id));
            $this->redirect($this->getReferer());
        }

        return $id;
    }

    private function getChroma(): ChromaDb
    {
        $chroma = ChromaDb::fromConfig();

        if ($chroma === null) {
            throw new \RuntimeException('GenAI embedding configuration is missing (genAIChromaDBOllamaUrl, genAIChromaDBOllamaModel).');
        }

        return $chroma;
    }

    private function abortDeletion(int $undoId, int $id, \Throwable $e): void
    {
        // The record will not be deleted -> its undo entry must not exist
        Database::getInstance()->prepare('DELETE FROM tl_undo WHERE id=?')->execute($undoId);

        $this->log("Deletion of ID {$id} aborted, vectors could not be removed: {$e->getMessage()}", LogLevel::ERROR);

        Message::addError(\sprintf(
            $GLOBALS['TL_LANG']['tl_genai_documents']['deleteVectorError'] ?? 'ID %d was not deleted because its embeddings could not be removed from the vector database: %s',
            $id,
            $e->getMessage()
        ));

        $this->redirect($this->getReferer());
    }

    private static function specialcharsValue(string $value): string
    {
        return \htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function log(string $message, string $level): void
    {
        try {
            System::getContainer()->get('monolog.logger.contao')->log(
                $level,
                '[GenAI] ' . $message,
                ['contao' => new ContaoContext(__CLASS__, $level === LogLevel::ERROR ? ContaoContext::ERROR : ContaoContext::GENERAL)]
            );
        } catch (\Throwable) {
            // Logger not available
        }
    }
}
