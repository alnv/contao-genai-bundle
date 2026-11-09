<?php

declare(strict_types=1);

namespace Alnv\GenaiBundle\Backend;

use Contao\Backend;
use Contao\Database;
use Contao\Message;

/**
 * Backend operations for tl_genai_index
 */
class IndexBackend extends Backend
{
    /**
     * Purge all completed entries
     */
    public function purgeCompleted(): void
    {
        $db = Database::getInstance();
        
        $result = $db->execute("DELETE FROM tl_genai_index WHERE status = 'completed'");
        $count = $result->affectedRows;
        
        if ($count > 0) {
            Message::addConfirmation(sprintf(
                $GLOBALS['TL_LANG']['tl_genai_index']['purge_success'] ?? '%d completed entries removed.',
                $count
            ));
        } else {
            Message::addInfo(
                $GLOBALS['TL_LANG']['tl_genai_index']['no_completed'] ?? 'No completed entries found.'
            );
        }

        $this->redirect($this->getReferer());
    }

    /**
     * Retry all failed entries
     */
    public function retryFailed(): void
    {
        $db = Database::getInstance();
        
        $result = $db->execute("
            UPDATE tl_genai_index 
            SET status = 'pending', 
                retry_count = 0, 
                error_message = NULL,
                tstamp = UNIX_TIMESTAMP()
            WHERE status = 'failed'
        ");
        $count = $result->affectedRows;
        
        if ($count > 0) {
            Message::addConfirmation(sprintf(
                $GLOBALS['TL_LANG']['tl_genai_index']['retry_success'] ?? '%d failed entries reset to pending.',
                $count
            ));
        } else {
            Message::addInfo(
                $GLOBALS['TL_LANG']['tl_genai_index']['no_failed'] ?? 'No failed entries found.'
            );
        }

        $this->redirect($this->getReferer());
    }
}
