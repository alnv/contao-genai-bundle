<?php

declare(strict_types=1);

namespace Alnv\GenaiBundle\EventListener;

use Alnv\GenaiBundle\Service\IndexQueue;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;

/**
 * DCA callbacks for queueing page changes
 * 
 * Only pages are indexed via PageCrawler.
 */
class ContentChangeListener
{
    public function __construct(
        private readonly IndexQueue $indexQueue
    ) {
    }

    /**
     * Queue page for indexing on save
     */
    #[AsCallback(table: 'tl_page', target: 'config.onsubmit')]
    public function onPageSubmit(DataContainer $dc): void
    {
        if (!$dc->id) {
            return;
        }

        $this->indexQueue->queuePage((int) $dc->id, 3);
    }

    /**
     * Mark page for deletion
     */
    #[AsCallback(table: 'tl_page', target: 'config.ondelete')]
    public function onPageDelete(DataContainer $dc): void
    {
        if (!$dc->id) {
            return;
        }

        $this->indexQueue->markForDeletion(
            IndexQueue::TYPE_PAGE,
            'tl_page',
            (int) $dc->id
        );
    }
}
