<?php

use Contao\ArrayUtil;

use Alnv\GenaiBundle\Vectors\ChromaDb;
use Contao\Config;
// $objChroma = new ChromaDb();
// $objChroma->setOllamaEmbedding(Config::get('genAIChromaDBOllamaUrl'), Config::get('genAIChromaDBOllamaModel'));
// $objChroma->deleteAll();
// $objChroma->add();
// dd($objChroma->count());

ArrayUtil::arrayInsert($GLOBALS['BE_MOD'], 3, [
    'genai-modules' => [
        'genai-chats' => [
            'name' => 'chats',
            'tables' => [
                'tl_genai_chats'
            ]
        ],
        'genai-assistants' => [
            'name' => 'assistants',
            'tables' => [
                'tl_genai_assistants'
            ]
        ],
        'genai-documents' => [
            'name' => 'documents',
            'tables' => [
                'tl_genai_documents_collections',
                'tl_genai_documents'
            ]
        ]
    ]
]);