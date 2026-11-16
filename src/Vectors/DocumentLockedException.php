<?php

declare(strict_types=1);

namespace Alnv\GenaiBundle\Vectors;

/**
 * Thrown when a document is already being synchronized by another process.
 */
class DocumentLockedException extends \RuntimeException
{
}
