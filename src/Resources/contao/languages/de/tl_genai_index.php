<?php

// Legends
$GLOBALS['TL_LANG']['tl_genai_index']['source_legend'] = 'Quelle';
$GLOBALS['TL_LANG']['tl_genai_index']['status_legend'] = 'Status';
$GLOBALS['TL_LANG']['tl_genai_index']['meta_legend'] = 'Metadaten';
$GLOBALS['TL_LANG']['tl_genai_index']['date_legend'] = 'Zeitstempel';

// Fields
$GLOBALS['TL_LANG']['tl_genai_index']['source_type'] = ['Quelltyp', 'Art der Quelle (nur Seite unterstützt)'];
$GLOBALS['TL_LANG']['tl_genai_index']['source_table'] = ['Quelltabelle', 'Name der Datenbanktabelle'];
$GLOBALS['TL_LANG']['tl_genai_index']['source_id'] = ['Quell-ID', 'ID des Datensatzes in der Quelltabelle'];
$GLOBALS['TL_LANG']['tl_genai_index']['source_url'] = ['URL', 'URL der Quelle'];
$GLOBALS['TL_LANG']['tl_genai_index']['title'] = ['Titel', 'Titel der Quelle'];
$GLOBALS['TL_LANG']['tl_genai_index']['status'] = ['Status', 'Aktueller Verarbeitungsstatus'];
$GLOBALS['TL_LANG']['tl_genai_index']['priority'] = ['Priorität', '1 = höchste, 10 = niedrigste'];
$GLOBALS['TL_LANG']['tl_genai_index']['content_hash'] = ['Content-Hash', 'Hash des aktuellen Inhalts'];
$GLOBALS['TL_LANG']['tl_genai_index']['previous_hash'] = ['Vorheriger Hash', 'Hash des vorherigen Inhalts'];
$GLOBALS['TL_LANG']['tl_genai_index']['processed_at'] = ['Verarbeitet am', 'Zeitpunkt der letzten Verarbeitung'];
$GLOBALS['TL_LANG']['tl_genai_index']['retry_count'] = ['Versuche', 'Anzahl der fehlgeschlagenen Versuche'];
$GLOBALS['TL_LANG']['tl_genai_index']['max_retries'] = ['Max. Versuche', 'Maximale Anzahl an Versuchen'];
$GLOBALS['TL_LANG']['tl_genai_index']['error_message'] = ['Fehlermeldung', 'Letzte Fehlermeldung'];
$GLOBALS['TL_LANG']['tl_genai_index']['collection_id'] = ['Collection', 'Zugehörige Dokument-Collection'];
$GLOBALS['TL_LANG']['tl_genai_index']['root_page'] = ['Startpunkt', 'Zugehöriger Startpunkt (bestimmt Sprache)'];
$GLOBALS['TL_LANG']['tl_genai_index']['tstamp'] = ['Geändert am', 'Zeitpunkt der letzten Änderung'];

// Status options
$GLOBALS['TL_LANG']['tl_genai_index']['status_options'] = [
    'pending' => 'Ausstehend',
    'processing' => 'In Bearbeitung',
    'completed' => 'Abgeschlossen',
    'failed' => 'Fehlgeschlagen',
    'deleted' => 'Zum Löschen markiert'
];

// Operations
$GLOBALS['TL_LANG']['tl_genai_index']['show'] = ['Details', 'Details des Eintrags ID %s anzeigen'];
$GLOBALS['TL_LANG']['tl_genai_index']['delete'] = ['Löschen', 'Eintrag ID %s löschen'];
$GLOBALS['TL_LANG']['tl_genai_index']['purge_completed'] = ['Abgeschlossene löschen', 'Alle abgeschlossenen Einträge entfernen'];
$GLOBALS['TL_LANG']['tl_genai_index']['retry_failed'] = ['Fehlgeschlagene wiederholen', 'Alle fehlgeschlagenen Einträge erneut versuchen'];

// Messages
$GLOBALS['TL_LANG']['tl_genai_index']['purge_success'] = '%d abgeschlossene Einträge wurden entfernt.';
$GLOBALS['TL_LANG']['tl_genai_index']['no_completed'] = 'Keine abgeschlossenen Einträge gefunden.';
$GLOBALS['TL_LANG']['tl_genai_index']['retry_success'] = '%d fehlgeschlagene Einträge wurden zurückgesetzt.';
$GLOBALS['TL_LANG']['tl_genai_index']['no_failed'] = 'Keine fehlgeschlagenen Einträge gefunden.';
