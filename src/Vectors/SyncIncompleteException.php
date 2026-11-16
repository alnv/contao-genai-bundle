<?php

declare(strict_types=1);

namespace Alnv\GenaiBundle\Vectors;

/**
 * A document sync stopped at its deadline. The embedded part is stored in the collection,
 * the next sync continues with the remaining chunks (the document stays pending).
 */
class SyncIncompleteException extends \RuntimeException
{
    public function __construct(
        public readonly int $done,
        public readonly int $total
    ) {
        parent::__construct(\sprintf('Synchronization incomplete: %d of %d chunks embedded, continues with the next run.', $done, $total));
    }
}
