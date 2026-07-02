<?php

use Alnv\GenaiBundle\Vectors\ChromaDb;
use Contao\Environment;
use Contao\Controller;
use Contao\Message;
use Contao\DC_Table;
use Contao\Config;
use Contao\Input;

$GLOBALS['TL_DCA']['tl_genai_documents'] = [
    'config' => [
        'dataContainer' => DC_Table::class,
        'backendSearchIgnore' => true,
        'ptable' => 'tl_genai_documents_collections',
        'onload_callback' => [
            function () {
                $objChroma = new ChromaDb();

                if (Input::get('resetAll')) {
                    $objChroma->setOllamaEmbedding(Config::get('genAIChromaDBOllamaUrl'), Config::get('genAIChromaDBOllamaModel'));
                    $objChroma->deleteAll();

                    Controller::redirect(\preg_replace('/&(amp;)?resetAll=[^&]*/i', '', \preg_replace('/&(amp;)?' . \preg_quote(Input::get('resetAll'), '/') . '=[^&]*/i', '', Environment::get('request'))));
                }

                Message::addInfo('Anzahl Chunks in der Vektordatenbank: ' . $objChroma->count());
            }
        ],
        'sql' => [
            'keys' => [
                'id' => 'primary'
            ]
        ]
    ],
    'list' => [
        'sorting' => [
            'mode' => 4,
            'fields' => ['synced_at DESC'],
            'panelLayout' => 'filter,limit;sort,search',
            'headerFields' => ['title'],
            'child_record_callback' => function ($arrRow) {
                $synced = ($arrRow['synced_at'] ? \date(Config::get('datimFormat'), $arrRow['synced_at']) : '');

                return ($arrRow['title'] ?? '-') . ($synced ? ('<span style="font-size: 10px; padding: 5px;color: #fff; background: green;border-radius: 10px;margin-left:5px;">'. $synced .'</span>') : '');
            }
        ],
        'label' => [],
        'global_operations' => [
            'resetAll' => [
                'href' => 'resetAll=1',
                'class' => 'header_edit_all',
                'attributes' => 'onclick="Backend.getScrollOffset()" accesskey="e"'
            ],
            'all' => [
                'href' => 'act=select',
                'class' => 'header_edit_all',
                'attributes' => 'onclick="Backend.getScrollOffset()" accesskey="e"'
            ]
        ],
        'operations' => [
            'edit' => [
                'icon' => 'edit.svg',
                'href' => 'act=edit'
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
        '__selector__' => [],
        'default' => 'title;content;url,text'
    ],
    'subpalettes' => [],
    'fields' => [
        'id' => [
            'sql' => ['type' => 'integer', 'autoincrement' => true, 'notnull' => true, 'unsigned' => true]
        ],
        'pid' => [
            'sql' => ['type' => 'integer', 'notnull' => false, 'unsigned' => true, 'default' => 0]
        ],
        'tstamp' => [
            'flag' => 6,
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
        ],
        'content' => [
            'inputType' => 'multiColumnWizard',
            'eval' => [
                'tl_class' => 'long clr',
                'columnFields' => [
                    'chunkNumber' => [
                        'label' => ['Nummer'],
                        'inputType' => 'text',
                        'eval' => [
                            'style' => 'width:15%;min-width:100%;'
                        ]
                    ],
                    'formattedContent' => [
                        'label' => ['Inhalt'],
                        'inputType' => 'textarea',
                        'eval' => [
                            'style' => 'width:85%;min-width:100%;'
                        ]
                    ]
                ]
            ],
            'search' => true,
            'sql' => "LONGBLOB NULL"
        ],
        'url' => [
            'inputType' => 'text',
            'eval' => [
                'tl_class' => 'w50'
            ],
            'search' => true,
            'sql' => "text NULL"
        ],
        'language' => [
            'inputType' => 'text',
            'eval' => [
                'tl_class' => 'w50'
            ],
            'search' => true,
            'sql' => "varchar(12) NOT NULL default ''"
        ],
        'pageId' => [
            'search' => true,
            'sql' => "varchar(64) NOT NULL default ''"
        ],
        'created_at' => [
            'flag' => 6,
            'sorting' => true,
            'sql' => ['type' => 'integer', 'notnull' => false, 'unsigned' => true, 'default' => 0]
        ],
        'updated_at' => [
            'flag' => 6,
            'sorting' => true,
            'sql' => ['type' => 'integer', 'notnull' => false, 'unsigned' => true, 'default' => 0]
        ],
        'synced_at' => [
            'flag' => 6,
            'sorting' => true,
            'sql' => ['type' => 'integer', 'notnull' => false, 'unsigned' => true, 'default' => 0]
        ]
    ]
];