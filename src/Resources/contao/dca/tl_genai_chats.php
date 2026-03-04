<?php

use Contao\DataContainer;
use Contao\DC_Table;

$GLOBALS['TL_DCA']['tl_genai_chats'] = [
    'config' => [
        'dataContainer' => DC_Table::class,
        'backendSearchIgnore' => true,
        'sql' => [
            'keys' => [
                'id' => 'primary'
            ]
        ]
    ],
    'list' => [
        'sorting' => [
            'mode' => DataContainer::MODE_SORTED,
            'panelLayout' => 'filter;sort,search',
            'fields' => ['name']
        ],
        'label' => [
            'fields' => ['name'],
            'showColumns' => true
        ],
        'operations' => [
            'edit' => [
                'href' => 'act=edit',
                'icon' => 'edit.svg'
            ],
            'delete' => [
                'href' => 'act=delete',
                'icon' => 'delete.svg',
                'attributes' => 'onclick="if(!confirm(\'' . ($GLOBALS['TL_LANG']['MSC']['deleteConfirm'] ?? '') . '\'))return false;Backend.getScrollOffset()"'
            ],
            'show' => [
                'href' => 'act=show',
                'icon' => 'show.svg'
            ]
        ]
    ],
    'palettes' => [
        '__selector__' => ['type'],
        'default' => 'type,name',
        'ollama' => 'type,name;url,model',
        'chatgpt' => 'type,name;token,model'
    ],
    'fields' => [
        'id' => [
            'sql' => ['type' => 'integer', 'autoincrement' => true, 'notnull' => true, 'unsigned' => true]
        ],
        'tstamp' => [
            'sql' => ['type' => 'integer', 'notnull' => false, 'unsigned' => true, 'default' => 0]
        ],
        'name' => [
            'inputType' => 'text',
            'eval' => [
                'mandatory' => true,
                'maxlength' => 255,
                'tl_class' => 'w50'
            ],
            'search' => true,
            'sql' => ['type' => 'string', 'length' => 255, 'default' => '']
        ],
        'type' => [
            'inputType' => 'select',
            'eval' => [
                'mandatory' => true,
                'tl_class' => 'w50',
                'includeBlankOption' => true,
                'submitOnChange' => true
            ],
            'options' => ['ollama' => 'Ollama', 'chatgpt' => 'ChatGPT'],
            'sql' => ['type' => 'string', 'length' => 32, 'default' => '']
        ],
        'url' => [
            'inputType' => 'text',
            'eval' => [
                'mandatory' => true,
                'maxlength' => 255,
                'tl_class' => 'w50',
                'decodeEntities' => true
            ],
            'search' => true,
            'sql' => ['type' => 'string', 'length' => 255, 'default' => '']
        ],
        'token' => [
            'inputType' => 'text',
            'eval' => [
                'mandatory' => true,
                'maxlength' => 255,
                'tl_class' => 'w50',
                'decodeEntities' => true
            ],
            'search' => true,
            'sql' => ['type' => 'string', 'length' => 255, 'default' => '']
        ],
        'model' => [
            'inputType' => 'text',
            'eval' => [
                'mandatory' => true,
                'maxlength' => 64,
                'tl_class' => 'w50'
            ],
            'search' => true,
            'sql' => ['type' => 'string', 'length' => 64, 'default' => '']
        ]
    ]
];