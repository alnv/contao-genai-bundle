<?php

declare(strict_types=1);

namespace Alnv\GenaiBundle\Tests\Service;

use Alnv\GenaiBundle\Service\IndexQueue;
use PHPUnit\Framework\TestCase;

/**
 * Tests for IndexQueue service
 */
class IndexQueueTest extends TestCase
{
    public function testStatusConstants(): void
    {
        $this->assertSame('pending', IndexQueue::STATUS_PENDING);
        $this->assertSame('processing', IndexQueue::STATUS_PROCESSING);
        $this->assertSame('completed', IndexQueue::STATUS_COMPLETED);
        $this->assertSame('failed', IndexQueue::STATUS_FAILED);
        $this->assertSame('deleted', IndexQueue::STATUS_DELETED);
    }

    public function testTypeConstants(): void
    {
        $this->assertSame('page', IndexQueue::TYPE_PAGE);
        $this->assertSame('article', IndexQueue::TYPE_ARTICLE);
        $this->assertSame('content', IndexQueue::TYPE_CONTENT);
        $this->assertSame('news', IndexQueue::TYPE_NEWS);
        $this->assertSame('event', IndexQueue::TYPE_EVENT);
        $this->assertSame('file', IndexQueue::TYPE_FILE);
    }

    public function testConstructorWithLogger(): void
    {
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $queue = new IndexQueue($logger);
        
        $this->assertInstanceOf(IndexQueue::class, $queue);
    }

    public function testConstructorWithoutLogger(): void
    {
        $queue = new IndexQueue();
        
        $this->assertInstanceOf(IndexQueue::class, $queue);
    }
}
