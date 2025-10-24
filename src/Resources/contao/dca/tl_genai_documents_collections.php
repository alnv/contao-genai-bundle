<?php

use Contao\DC_Table;

$GLOBALS['TL_DCA']['tl_genai_documents_collections'] = [
    'config' => [
        'dataContainer' => DC_Table::class,
        'enableVersioning' => true,
        'ctable' => ['tl_genai_documents'],
        'onload_callback' => [],
        'onsubmit_callback' => [],
        'sql' => [
            'keys' => [
                'id' => 'primary'
            ]
        ]
    ],
    'list' => [
        'sorting' => [
            'mode' => 2,
            'flag' => 12,
            'fields' => ['title'],
            'panelLayout' => 'filter,limit;sort,search'
        ],
        'label' => [
            'fields' => ['title'],
            'showColumns' => true
        ],
        'operations' => [
            'edit' => [
                'icon' => 'edit.svg',
                'href' => 'act=edit'
            ],
            'children' => [
                'icon' => 'children.svg',
                'href' => 'table=tl_genai_documents'
            ],
            'delete' => [
                'href' => 'act=delete',
                'icon' => 'delete.svg',
                'attributes' => 'onclick="if(!confirm(\'' . ($GLOBALS['TL_LANG']['MSC']['deleteConfirm'] ?? '') . '\'))return false;Backend.getScrollOffset()"'
            ]
        ]
    ],
    'palettes' => [
        '__selector__' => [],
        'default' => 'title'
    ],
    'subpalettes' => [],
    'fields' => [
        'id' => [
            'sql' => ['type' => 'integer', 'autoincrement' => true, 'notnull' => true, 'unsigned' => true]
        ],
        'tstamp' => [
            'sql' => ['type' => 'integer', 'notnull' => false, 'unsigned' => true, 'default' => 0]
        ],
        'title' => [
            'inputType' => 'text',
            'eval' => [
                'mandatory' => true,
                'tl_class' => 'w50'
            ],
            'search' => true,
            'sql' => "text NULL"
        ]
    ]
];