<?php

declare(strict_types=1);

namespace Alnv\GenaiBundle\Helpers;

use Contao\System;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/**
 * Lock factory with a fixed lock directory (var/lock/genai).
 *
 * new FlockStore() without a path uses sys_get_temp_dir(), which differs between php-fpm
 * (web cron, backend buttons) and the CLI (contao:cron, console commands) - the locks
 * would then not see each other.
 */
class Locks
{
    private static ?LockFactory $factory = null;

    public static function factory(): LockFactory
    {
        if (self::$factory === null) {
            $dir = System::getContainer()->getParameter('kernel.project_dir') . '/var/lock/genai';

            if (!\is_dir($dir)) {
                @\mkdir($dir, 0775, true);
            }

            self::$factory = new LockFactory(new FlockStore(\is_dir($dir) && \is_writable($dir) ? $dir : null));
        }

        return self::$factory;
    }
}
