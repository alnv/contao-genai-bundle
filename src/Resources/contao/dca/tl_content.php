<?php

// Add palette
$GLOBALS['TL_DCA']['tl_content']['palettes']['genai_chat'] = '{type_legend},type,headline;{genai_legend},genai_assistant,text;{labels_legend:hide},genai_placeholder,genai_send_label,genai_stop_label,genai_new_chat_label,genai_copy_label,genai_copied_label,genai_loading_label,genai_error_label,genai_retry_label,genai_sources_label,genai_user_label;{template_legend:hide},customTpl;{protected_legend:hide},protected;{invisible_legend:hide},invisible,start,stop';

// Add fields
$GLOBALS['TL_DCA']['tl_content']['fields']['genai_assistant'] = [
    'label' => &$GLOBALS['TL_LANG']['tl_content']['genai_assistant'],
    'inputType' => 'select',
    'foreignKey' => 'tl_genai_assistants.name',
    'eval' => [
        'mandatory' => true,
        'chosen' => true,
        'includeBlankOption' => true,
        'tl_class' => 'w50'
    ],
    'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]
];

// Label fields with defaults
$GLOBALS['TL_DCA']['tl_content']['fields']['genai_placeholder'] = [
    'label' => &$GLOBALS['TL_LANG']['tl_content']['genai_placeholder'],
    'inputType' => 'text',
    'eval' => ['maxlength' => 255, 'tl_class' => 'w50'],
    'sql' => "varchar(255) NOT NULL default ''"
];

$GLOBALS['TL_DCA']['tl_content']['fields']['genai_send_label'] = [
    'label' => &$GLOBALS['TL_LANG']['tl_content']['genai_send_label'],
    'inputType' => 'text',
    'eval' => ['maxlength' => 64, 'tl_class' => 'w50'],
    'sql' => "varchar(64) NOT NULL default ''"
];

$GLOBALS['TL_DCA']['tl_content']['fields']['genai_stop_label'] = [
    'label' => &$GLOBALS['TL_LANG']['tl_content']['genai_stop_label'],
    'inputType' => 'text',
    'eval' => ['maxlength' => 64, 'tl_class' => 'w50'],
    'sql' => "varchar(64) NOT NULL default ''"
];

$GLOBALS['TL_DCA']['tl_content']['fields']['genai_new_chat_label'] = [
    'label' => &$GLOBALS['TL_LANG']['tl_content']['genai_new_chat_label'],
    'inputType' => 'text',
    'eval' => ['maxlength' => 64, 'tl_class' => 'w50'],
    'sql' => "varchar(64) NOT NULL default ''"
];

$GLOBALS['TL_DCA']['tl_content']['fields']['genai_copy_label'] = [
    'label' => &$GLOBALS['TL_LANG']['tl_content']['genai_copy_label'],
    'inputType' => 'text',
    'eval' => ['maxlength' => 64, 'tl_class' => 'w50'],
    'sql' => "varchar(64) NOT NULL default ''"
];

$GLOBALS['TL_DCA']['tl_content']['fields']['genai_copied_label'] = [
    'label' => &$GLOBALS['TL_LANG']['tl_content']['genai_copied_label'],
    'inputType' => 'text',
    'eval' => ['maxlength' => 64, 'tl_class' => 'w50'],
    'sql' => "varchar(64) NOT NULL default ''"
];

$GLOBALS['TL_DCA']['tl_content']['fields']['genai_loading_label'] = [
    'label' => &$GLOBALS['TL_LANG']['tl_content']['genai_loading_label'],
    'inputType' => 'text',
    'eval' => ['maxlength' => 128, 'tl_class' => 'w50'],
    'sql' => "varchar(128) NOT NULL default ''"
];

$GLOBALS['TL_DCA']['tl_content']['fields']['genai_error_label'] = [
    'label' => &$GLOBALS['TL_LANG']['tl_content']['genai_error_label'],
    'inputType' => 'text',
    'eval' => ['maxlength' => 255, 'tl_class' => 'w50'],
    'sql' => "varchar(255) NOT NULL default ''"
];

$GLOBALS['TL_DCA']['tl_content']['fields']['genai_retry_label'] = [
    'label' => &$GLOBALS['TL_LANG']['tl_content']['genai_retry_label'],
    'inputType' => 'text',
    'eval' => ['maxlength' => 64, 'tl_class' => 'w50'],
    'sql' => "varchar(64) NOT NULL default ''"
];

$GLOBALS['TL_DCA']['tl_content']['fields']['genai_sources_label'] = [
    'label' => &$GLOBALS['TL_LANG']['tl_content']['genai_sources_label'],
    'inputType' => 'text',
    'eval' => ['maxlength' => 64, 'tl_class' => 'w50'],
    'sql' => "varchar(64) NOT NULL default ''"
];

$GLOBALS['TL_DCA']['tl_content']['fields']['genai_user_label'] = [
    'label' => &$GLOBALS['TL_LANG']['tl_content']['genai_user_label'],
    'inputType' => 'text',
    'eval' => ['maxlength' => 64, 'tl_class' => 'w50'],
    'sql' => "varchar(64) NOT NULL default ''"
];
