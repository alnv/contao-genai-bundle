<?php

use Contao\ArrayUtil;
use Alnv\GenaiBundle\Backend\IndexBackend;
use Alnv\GenaiBundle\ContentElement\ChatElement;

// Register content element category
$GLOBALS['TL_LANG']['CTE']['genai'] = 'GenAI';

// Register content element
$GLOBALS['TL_CTE']['genai']['genai_chat'] = ChatElement::class;

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
        ],
        'genai-index' => [
            'name' => 'index',
            'tables' => [
                'tl_genai_index'
            ],
            'purge_completed' => [IndexBackend::class, 'purgeCompleted'],
            'retry_failed' => [IndexBackend::class, 'retryFailed']
        ]
    ]
]);