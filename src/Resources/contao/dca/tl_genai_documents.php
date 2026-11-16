<?php

use Alnv\GenaiBundle\Backend\DocumentBackend;
use Contao\DC_Table;

$GLOBALS['TL_DCA']['tl_genai_documents'] = [
    'config' => [
        'dataContainer' => DC_Table::class,
        'backendSearchIgnore' => true,
        'closed' => true,
        'ptable' => 'tl_genai_documents_collections',
        'ctable' => ['tl_genai_documents_chunks'],
        'onload_callback' => [],
        'ondelete_callback' => [[DocumentBackend::class, 'onDeleteDocument']],
        'onundo_callback' => [[DocumentBackend::class, 'onUndoDocument']],
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'pid' => 'index',
                'url' => 'index',
                'content_hash' => 'index',
                'synced_at' => 'index',
                'rootPageId' => 'index'
            ]
        ]
    ],
    'list' => [
        'sorting' => [
            'mode' => 4,
            'fields' => ['synced_at DESC'],
            'panelLayout' => 'filter,limit;sort,search',
            'headerFields' => ['title'],
            'child_record_callback' => [DocumentBackend::class, 'renderDocumentRow']
        ],
        'label' => [],
        'global_operations' => [
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
            'children' => [
                'icon' => 'children.svg',
                'href' => 'table=tl_genai_documents_chunks'
            ],
            'syncDocument' => [
                'icon' => 'sync.svg',
                'href' => 'key=syncDocument'
            ],
            'removeVectors' => [
                'icon' => 'unpublished.svg',
                'href' => 'key=removeVectors',
                'attributes' => 'onclick="if(!confirm(\'' . ($GLOBALS['TL_LANG']['tl_genai_documents']['removeConfirm'] ?? 'Remove this document from the vector database?') . '\'))return false;Backend.getScrollOffset()"'
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
        'default' => 'title;url,language'
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
        ],
        'sync_disabled' => [
            'filter' => true,
            'inputType' => 'checkbox',
            'eval' => ['tl_class' => 'w50 m12'],
            'sql' => ['type' => 'string', 'length' => 1, 'fixed' => true, 'notnull' => true, 'default' => '']
        ],
        'sync_error' => [
            'sql' => ['type' => 'string', 'length' => 255, 'notnull' => true, 'default' => '']
        ],
        'sync_failed_at' => [
            'flag' => 6,
            'sql' => ['type' => 'integer', 'notnull' => true, 'unsigned' => true, 'default' => 0]
        ],
        'content_hash' => [
            'search' => true,
            'sql' => "varchar(64) NOT NULL default ''"
        ],
        'rootPageId' => [
            'search' => true,
            'sql' => ['type' => 'integer', 'notnull' => false, 'unsigned' => true, 'default' => 0]
        ],
        'source_type' => [
            'filter' => true,
            'sorting' => true,
            'sql' => "varchar(32) NOT NULL default 'page'"
        ],
        'source_id' => [
            'search' => true,
            'sql' => "varchar(255) NOT NULL default ''"
        ],
        'metadata' => [
            'sql' => "BLOB NULL"
        ]
    ]
];