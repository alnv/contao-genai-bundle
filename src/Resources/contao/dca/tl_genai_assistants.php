<?php

use Alnv\GenaiBundle\Helpers\Getters;
use Contao\DataContainer;
use Contao\DC_Table;

$GLOBALS['TL_DCA']['tl_genai_assistants'] = [
    'config' => [
        'dataContainer' => DC_Table::class,
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
            'fields' => ['name', 'assistantName'],
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
        'default' => 'name;assistantName,assistantDialog,instructions;chat'
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
                'maxlength' => 128,
                'tl_class' => 'w50'
            ],
            'search' => true,
            'sql' => ['type' => 'string', 'length' => 128, 'default' => '']
        ],
        'assistantName' => [
            'inputType' => 'text',
            'eval' => [
                'mandatory' => true,
                'maxlength' => 128,
                'tl_class' => 'w50'
            ],
            'search' => true,
            'sql' => ['type' => 'string', 'length' => 128, 'default' => '']
        ],
        'assistantDialog' => [
            'inputType' => 'textarea',
            'eval' => [
                'mandatory' => false,
                'tl_class' => 'clear'
            ],
            'search' => true,
            'sql' => 'text NULL'
        ],
        'instructions' => [
            'inputType' => 'textarea',
            'eval' => [
                'doNotCopy' => true,
                'tl_class' => 'clr',
                'decodeEntities' => true
            ],
            'sql' => 'text NULL'
        ],
        'chat' => [
            'inputType' => 'select',
            'eval' => [
                'mandatory' => true,
                'tl_class' => 'w50',
                'includeBlankOption' => true
            ],
            'options_callback' => function () {
                $arrChats = [];

                foreach (Getters::getAllChats() as $arrChat) {
                    $arrChats[$arrChat['id']] = $arrChat['name'];
                }

                return $arrChats;
            },
            'sql' => ['type' => 'integer', 'notnull' => false, 'unsigned' => true, 'default' => 0]
        ]
    ]
];