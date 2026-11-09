<?php

use Contao\DC_Table;
use Contao\DataContainer;

$GLOBALS['TL_DCA']['tl_genai_index'] = [
    'config' => [
        'dataContainer' => DC_Table::class,
        'closed' => true,
        'notEditable' => true,
        'notCopyable' => true,
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'status' => 'index',
                'source_type,source_id' => 'index',
                'source_url' => 'index',
                'collection_id' => 'index',
                'priority,status' => 'index',
                'source_table,source_id' => 'unique'
            ]
        ]
    ],
    'list' => [
        'sorting' => [
            'mode' => DataContainer::MODE_SORTABLE,
            'fields' => ['tstamp DESC'],
            'panelLayout' => 'filter;sort,search,limit',
            'defaultSearchField' => 'title'
        ],
        'label' => [
            'fields' => ['source_type', 'title', 'status', 'tstamp'],
            'showColumns' => true,
            'label_callback' => ['tl_genai_index', 'formatLabel']
        ],
        'global_operations' => [
            'purge_completed' => [
                'href' => 'key=purge_completed',
                'icon' => 'delete.svg',
                'attributes' => 'onclick="if(!confirm(\'Alle abgeschlossenen Einträge löschen?\'))return false;Backend.getScrollOffset()"'
            ],
            'retry_failed' => [
                'href' => 'key=retry_failed',
                'icon' => 'sync.svg',
                'attributes' => 'onclick="if(!confirm(\'Alle fehlgeschlagenen Einträge erneut versuchen?\'))return false;Backend.getScrollOffset()"'
            ]
        ],
        'operations' => [
            'show' => [
                'href' => 'act=show',
                'icon' => 'show.svg'
            ],
            'delete' => [
                'href' => 'act=delete',
                'icon' => 'delete.svg',
                'attributes' => 'onclick="if(!confirm(\'' . ($GLOBALS['TL_LANG']['MSC']['deleteConfirm'] ?? 'Delete?') . '\'))return false;Backend.getScrollOffset()"'
            ]
        ]
    ],
    'palettes' => [
        'default' => '{source_legend},source_type,source_table,source_id,source_url,title;{status_legend},status,priority,retry_count,error_message;{meta_legend},root_page,content_hash,collection_id;{date_legend},tstamp,processed_at'
    ],
    'fields' => [
        'id' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]
        ],
        'tstamp' => [
            'label' => &$GLOBALS['TL_LANG']['tl_genai_index']['tstamp'],
            'sorting' => true,
            'flag' => DataContainer::SORT_DAY_DESC,
            'eval' => ['rgxp' => 'datim'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]
        ],
        'source_type' => [
            'label' => &$GLOBALS['TL_LANG']['tl_genai_index']['source_type'],
            'filter' => true,
            'sorting' => true,
            'inputType' => 'select',
            'options' => ['page', 'article', 'content', 'news', 'event', 'file'],
            'eval' => ['tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 32, 'default' => 'page']
        ],
        'source_table' => [
            'label' => &$GLOBALS['TL_LANG']['tl_genai_index']['source_table'],
            'search' => true,
            'inputType' => 'text',
            'eval' => ['tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 64, 'default' => '']
        ],
        'source_id' => [
            'label' => &$GLOBALS['TL_LANG']['tl_genai_index']['source_id'],
            'search' => true,
            'inputType' => 'text',
            'eval' => ['tl_class' => 'w50', 'rgxp' => 'natural'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]
        ],
        'source_url' => [
            'label' => &$GLOBALS['TL_LANG']['tl_genai_index']['source_url'],
            'search' => true,
            'inputType' => 'text',
            'eval' => ['tl_class' => 'long'],
            'sql' => ['type' => 'string', 'length' => 500, 'default' => '']
        ],
        'title' => [
            'label' => &$GLOBALS['TL_LANG']['tl_genai_index']['title'],
            'search' => true,
            'sorting' => true,
            'inputType' => 'text',
            'eval' => ['tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 255, 'default' => '']
        ],
        'status' => [
            'label' => &$GLOBALS['TL_LANG']['tl_genai_index']['status'],
            'filter' => true,
            'sorting' => true,
            'inputType' => 'select',
            'options' => ['pending', 'processing', 'completed', 'failed', 'deleted'],
            'reference' => &$GLOBALS['TL_LANG']['tl_genai_index']['status_options'],
            'eval' => ['tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 16, 'default' => 'pending']
        ],
        'priority' => [
            'label' => &$GLOBALS['TL_LANG']['tl_genai_index']['priority'],
            'filter' => true,
            'sorting' => true,
            'inputType' => 'select',
            'options' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
            'eval' => ['tl_class' => 'w50'],
            'sql' => ['type' => 'smallint', 'unsigned' => true, 'default' => 5]
        ],
        'content_hash' => [
            'label' => &$GLOBALS['TL_LANG']['tl_genai_index']['content_hash'],
            'inputType' => 'text',
            'eval' => ['tl_class' => 'w50', 'readonly' => true],
            'sql' => ['type' => 'string', 'length' => 64, 'default' => '']
        ],
        'previous_hash' => [
            'label' => &$GLOBALS['TL_LANG']['tl_genai_index']['previous_hash'],
            'inputType' => 'text',
            'eval' => ['tl_class' => 'w50', 'readonly' => true],
            'sql' => ['type' => 'string', 'length' => 64, 'default' => '']
        ],
        'processed_at' => [
            'label' => &$GLOBALS['TL_LANG']['tl_genai_index']['processed_at'],
            'sorting' => true,
            'flag' => DataContainer::SORT_DAY_DESC,
            'eval' => ['rgxp' => 'datim'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]
        ],
        'retry_count' => [
            'label' => &$GLOBALS['TL_LANG']['tl_genai_index']['retry_count'],
            'filter' => true,
            'inputType' => 'text',
            'eval' => ['tl_class' => 'w50', 'rgxp' => 'natural'],
            'sql' => ['type' => 'smallint', 'unsigned' => true, 'default' => 0]
        ],
        'max_retries' => [
            'label' => &$GLOBALS['TL_LANG']['tl_genai_index']['max_retries'],
            'inputType' => 'text',
            'eval' => ['tl_class' => 'w50', 'rgxp' => 'natural'],
            'sql' => ['type' => 'smallint', 'unsigned' => true, 'default' => 3]
        ],
        'error_message' => [
            'label' => &$GLOBALS['TL_LANG']['tl_genai_index']['error_message'],
            'search' => true,
            'inputType' => 'textarea',
            'eval' => ['tl_class' => 'clr long', 'readonly' => true],
            'sql' => ['type' => 'text', 'notnull' => false]
        ],
        'collection_id' => [
            'label' => &$GLOBALS['TL_LANG']['tl_genai_index']['collection_id'],
            'filter' => true,
            'inputType' => 'select',
            'foreignKey' => 'tl_genai_documents_collections.title',
            'eval' => ['tl_class' => 'w50', 'includeBlankOption' => true],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]
        ],
        'language' => [
            'label' => &$GLOBALS['TL_LANG']['tl_genai_index']['language'],
            'inputType' => 'select',
            'options_callback' => static fn() => \Contao\System::getContainer()->get('contao.intl.locales')->getLocales(),
            'eval' => ['tl_class' => 'w50', 'includeBlankOption' => true],
            'sql' => ['type' => 'string', 'length' => 5, 'default' => '']
        ],
        'root_page' => [
            'label' => &$GLOBALS['TL_LANG']['tl_genai_index']['root_page'],
            'filter' => true,
            'inputType' => 'pageTree',
            'eval' => ['tl_class' => 'w50', 'fieldType' => 'radio'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]
        ]
    ]
];

/**
 * Callback class
 */
class tl_genai_index
{
    /**
     * Format label with status colors
     */
    public function formatLabel(array $row, string $label, DataContainer $dc, array $args): array
    {
        $statusColors = [
            'pending' => '#f59e0b',
            'processing' => '#3b82f6',
            'completed' => '#10b981',
            'failed' => '#ef4444',
            'deleted' => '#6b7280'
        ];

        $status = $row['status'] ?? 'pending';
        $color = $statusColors[$status] ?? '#6b7280';
        
        $args[2] = sprintf(
            '<span style="color:%s;font-weight:600">%s</span>',
            $color,
            strtoupper($status)
        );

        // Format timestamp
        if (!empty($row['tstamp'])) {
            $args[3] = date('d.m.Y H:i', (int) $row['tstamp']);
        }

        return $args;
    }
}
