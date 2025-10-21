<?php

use Contao\ArrayUtil;
// (new Alnv\GenaiBundle\Vectors\ChromaDb())->addData();

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
        ]
    ]
]);