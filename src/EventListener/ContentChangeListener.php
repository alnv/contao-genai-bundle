<?php

declare(strict_types=1);

namespace Alnv\GenaiBundle\EventListener;

use Alnv\GenaiBundle\Service\IndexQueue;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;

/**
 * DCA callbacks for queueing content changes
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

    /**
     * Queue article for indexing on save
     */
    #[AsCallback(table: 'tl_article', target: 'config.onsubmit')]
    public function onArticleSubmit(DataContainer $dc): void
    {
        if (!$dc->id) {
            return;
        }

        $this->indexQueue->queueArticle((int) $dc->id, 4);
    }

    /**
     * Mark article for deletion
     */
    #[AsCallback(table: 'tl_article', target: 'config.ondelete')]
    public function onArticleDelete(DataContainer $dc): void
    {
        if (!$dc->id) {
            return;
        }

        $this->indexQueue->markForDeletion(
            IndexQueue::TYPE_ARTICLE,
            'tl_article',
            (int) $dc->id
        );
    }

    /**
     * Queue content element for indexing (triggers article reindex)
     */
    #[AsCallback(table: 'tl_content', target: 'config.onsubmit')]
    public function onContentSubmit(DataContainer $dc): void
    {
        if (!$dc->id) {
            return;
        }

        $this->indexQueue->queueContent((int) $dc->id, 5);
    }

    /**
     * Queue content element deletion (triggers article reindex)
     */
    #[AsCallback(table: 'tl_content', target: 'config.ondelete')]
    public function onContentDelete(DataContainer $dc): void
    {
        if (!$dc->id) {
            return;
        }

        // Content deletion also triggers article reindex
        $this->indexQueue->queueContent((int) $dc->id, 5);
    }

    /**
     * Queue news article for indexing
     */
    #[AsCallback(table: 'tl_news', target: 'config.onsubmit')]
    public function onNewsSubmit(DataContainer $dc): void
    {
        if (!$dc->id) {
            return;
        }

        // News uses same queue mechanism
        $this->indexQueue->queueArticle((int) $dc->id, 4);
    }

    /**
     * Mark news for deletion
     */
    #[AsCallback(table: 'tl_news', target: 'config.ondelete')]
    public function onNewsDelete(DataContainer $dc): void
    {
        if (!$dc->id) {
            return;
        }

        $this->indexQueue->markForDeletion(
            IndexQueue::TYPE_NEWS,
            'tl_news',
            (int) $dc->id
        );
    }

    /**
     * Queue calendar event for indexing
     */
    #[AsCallback(table: 'tl_calendar_events', target: 'config.onsubmit')]
    public function onEventSubmit(DataContainer $dc): void
    {
        if (!$dc->id) {
            return;
        }

        $this->indexQueue->queueArticle((int) $dc->id, 4);
    }

    /**
     * Mark event for deletion
     */
    #[AsCallback(table: 'tl_calendar_events', target: 'config.ondelete')]
    public function onEventDelete(DataContainer $dc): void
    {
        if (!$dc->id) {
            return;
        }

        $this->indexQueue->markForDeletion(
            IndexQueue::TYPE_EVENT,
            'tl_calendar_events',
            (int) $dc->id
        );
    }
}
