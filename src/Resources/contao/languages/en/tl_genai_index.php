<?php

// Legends
$GLOBALS['TL_LANG']['tl_genai_index']['source_legend'] = 'Source';
$GLOBALS['TL_LANG']['tl_genai_index']['status_legend'] = 'Status';
$GLOBALS['TL_LANG']['tl_genai_index']['meta_legend'] = 'Metadata';
$GLOBALS['TL_LANG']['tl_genai_index']['date_legend'] = 'Timestamps';

// Fields
$GLOBALS['TL_LANG']['tl_genai_index']['source_type'] = ['Source type', 'Type of source (only page supported)'];
$GLOBALS['TL_LANG']['tl_genai_index']['source_table'] = ['Source table', 'Name of the database table'];
$GLOBALS['TL_LANG']['tl_genai_index']['source_id'] = ['Source ID', 'ID of the record in the source table'];
$GLOBALS['TL_LANG']['tl_genai_index']['source_url'] = ['URL', 'URL of the source'];
$GLOBALS['TL_LANG']['tl_genai_index']['title'] = ['Title', 'Title of the source'];
$GLOBALS['TL_LANG']['tl_genai_index']['status'] = ['Status', 'Current processing status'];
$GLOBALS['TL_LANG']['tl_genai_index']['priority'] = ['Priority', '1 = highest, 10 = lowest'];
$GLOBALS['TL_LANG']['tl_genai_index']['content_hash'] = ['Content hash', 'Hash of the current content'];
$GLOBALS['TL_LANG']['tl_genai_index']['previous_hash'] = ['Previous hash', 'Hash of the previous content'];
$GLOBALS['TL_LANG']['tl_genai_index']['processed_at'] = ['Processed at', 'Time of last processing'];
$GLOBALS['TL_LANG']['tl_genai_index']['retry_count'] = ['Retries', 'Number of failed attempts'];
$GLOBALS['TL_LANG']['tl_genai_index']['max_retries'] = ['Max retries', 'Maximum number of attempts'];
$GLOBALS['TL_LANG']['tl_genai_index']['error_message'] = ['Error message', 'Last error message'];
$GLOBALS['TL_LANG']['tl_genai_index']['collection_id'] = ['Collection', 'Associated document collection'];
$GLOBALS['TL_LANG']['tl_genai_index']['root_page'] = ['Root page', 'Associated root page (determines language)'];
$GLOBALS['TL_LANG']['tl_genai_index']['tstamp'] = ['Modified', 'Last modification time'];

// Status options
$GLOBALS['TL_LANG']['tl_genai_index']['status_options'] = [
    'pending' => 'Pending',
    'processing' => 'Processing',
    'completed' => 'Completed',
    'failed' => 'Failed',
    'deleted' => 'Marked for deletion'
];

// Operations
$GLOBALS['TL_LANG']['tl_genai_index']['show'] = ['Details', 'Show details of entry ID %s'];
$GLOBALS['TL_LANG']['tl_genai_index']['delete'] = ['Delete', 'Delete entry ID %s'];
$GLOBALS['TL_LANG']['tl_genai_index']['purge_completed'] = ['Purge completed', 'Remove all completed entries'];
$GLOBALS['TL_LANG']['tl_genai_index']['retry_failed'] = ['Retry failed', 'Retry all failed entries'];

// Messages
$GLOBALS['TL_LANG']['tl_genai_index']['purge_success'] = '%d completed entries removed.';
$GLOBALS['TL_LANG']['tl_genai_index']['no_completed'] = 'No completed entries found.';
$GLOBALS['TL_LANG']['tl_genai_index']['retry_success'] = '%d failed entries reset to pending.';
$GLOBALS['TL_LANG']['tl_genai_index']['no_failed'] = 'No failed entries found.';
