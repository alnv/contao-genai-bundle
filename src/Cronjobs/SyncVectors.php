<?php

namespace Alnv\GenaiBundle\Cronjobs;

use Contao\Config;
use Alnv\GenaiBundle\Vectors\ChromaDb;

class SyncVectors
{
    public function __invoke(): void
    {
        if (!Config::get('genAIChromaDBOllamaUrl') || !Config::get('genAIChromaDBOllamaModel')) {
            return;
        }

        $objChroma = new ChromaDb();
        $objChroma->setOllamaEmbedding(Config::get('genAIChromaDBOllamaUrl'), Config::get('genAIChromaDBOllamaModel'));
        // $objChroma->sync();
        $objChroma->add();
    }
}