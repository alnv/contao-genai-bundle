<?php

use Alnv\GenaiBundle\Helpers\DocumentChunks;
use Contao\DataContainer;
use Contao\DC_Table;
use Contao\StringUtil;

$GLOBALS['TL_DCA']['tl_genai_documents_chunks'] = [
    'config' => [
        'dataContainer' => DC_Table::class,
        'backendSearchIgnore' => true,
        'closed' => true,
        'notCopyable' => true,
        'ptable' => 'tl_genai_documents',
        'onsubmit_callback' => [
            static function (DataContainer $dc): void {
                if ($dc->activeRecord) {
                    DocumentChunks::markDocumentForSync((int)$dc->activeRecord->pid);
                }
            }
        ],
        'ondelete_callback' => [
            static function (DataContainer $dc): void {
                if ($dc->activeRecord) {
                    DocumentChunks::markDocumentForSync((int)$dc->activeRecord->pid);
                }
            }
        ],
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'pid,sorting' => 'index'
            ]
        ]
    ],
    'list' => [
        'sorting' => [
            'mode' => DataContainer::MODE_PARENT,
            'fields' => ['sorting'],
            'panelLayout' => 'search,limit',
            'headerFields' => ['title', 'url', 'synced_at'],
            'child_record_callback' => static function (array $row): string {
                $text = StringUtil::substr(\trim(\strip_tags((string)$row['content'])), 300);

                return '<strong>#' . (int)$row['chunkNumber'] . '</strong> '
                    . '<span style="color:var(--info)">(' . \mb_strlen((string)$row['content']) . ')</span><br>'
                    . StringUtil::specialchars($text);
            }
        ],
        'global_operations' => [
            'all' => [
                'href' => 'act=select',
                'class' => 'header_edit_all',
                'attributes' => 'onclick="Backend.getScrollOffset()" accesskey="e"'
            ]
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
        'default' => 'chunkNumber;content'
    ],
    'fields' => [
        'id' => [
            'sql' => ['type' => 'integer', 'autoincrement' => true, 'notnull' => true, 'unsigned' => true]
        ],
        'pid' => [
            'foreignKey' => 'tl_genai_documents.title',
            'relation' => ['type' => 'belongsTo', 'load' => 'lazy'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'notnull' => true, 'default' => 0]
        ],
        'tstamp' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'notnull' => true, 'default' => 0]
        ],
        'sorting' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'notnull' => true, 'default' => 0]
        ],
        'chunkNumber' => [
            'inputType' => 'text',
            'eval' => ['rgxp' => 'natural', 'mandatory' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'notnull' => true, 'default' => 0]
        ],
        'content' => [
            'search' => true,
            'inputType' => 'textarea',
            'eval' => ['style' => 'height:300px', 'decodeEntities' => true, 'tl_class' => 'clr'],
            'sql' => 'mediumtext NULL'
        ]
    ]
];
