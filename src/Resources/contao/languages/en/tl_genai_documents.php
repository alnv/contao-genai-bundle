<?php

$GLOBALS['TL_LANG']['tl_genai_documents']['title'] = ['Bezeichnung', ''];
$GLOBALS['TL_LANG']['tl_genai_documents']['children'] = 'Chunks von Dokument ID %s bearbeiten';
$GLOBALS['TL_LANG']['tl_genai_documents']['url'] = ['URL', ''];
$GLOBALS['TL_LANG']['tl_genai_documents']['language'] = ['Sprache', ''];
$GLOBALS['TL_LANG']['tl_genai_documents']['resetAll'] = ['Vektordatenbank zurücksetzen', ''];
$GLOBALS['TL_LANG']['tl_genai_documents']['sync_disabled'] = ['Nicht in Vektordatenbank', 'Das Dokument wurde aus der Vektordatenbank entfernt und wird vom Cronjob nicht neu eingebettet.'];
$GLOBALS['TL_LANG']['tl_genai_documents']['syncDocument'] = 'Dokument ID %s jetzt synchronisieren';
$GLOBALS['TL_LANG']['tl_genai_documents']['removeVectors'] = 'Dokument ID %s aus der Vektordatenbank entfernen';
$GLOBALS['TL_LANG']['tl_genai_documents']['removeConfirm'] = 'Embeddings dieses Dokuments aus der Vektordatenbank entfernen? Der Datensatz bleibt erhalten.';
$GLOBALS['TL_LANG']['tl_genai_documents']['syncSuccess'] = 'Dokument ID %d wurde synchronisiert (%d Vektoren).';
$GLOBALS['TL_LANG']['tl_genai_documents']['syncChanged'] = 'Dokument ID %d wurde während der Synchronisierung geändert und bleibt in der Warteschlange.';
$GLOBALS['TL_LANG']['tl_genai_documents']['syncPartial'] = 'Dokument ID %d: %d von %d Chunks eingebettet, der Cronjob setzt die Synchronisierung fort.';
$GLOBALS['TL_LANG']['tl_genai_documents']['syncLocked'] = 'Dokument ID %d wird gerade synchronisiert. Bitte später erneut versuchen.';
$GLOBALS['TL_LANG']['tl_genai_documents']['syncError'] = 'Dokument ID %d konnte nicht synchronisiert werden: %s';
$GLOBALS['TL_LANG']['tl_genai_documents']['removeSuccess'] = 'Dokument ID %d wurde aus der Vektordatenbank entfernt (%d Vektoren).';
$GLOBALS['TL_LANG']['tl_genai_documents']['removeError'] = 'Dokument ID %d konnte nicht aus der Vektordatenbank entfernt werden: %s';
$GLOBALS['TL_LANG']['tl_genai_documents']['deleteVectorError'] = 'ID %d wurde nicht gelöscht, weil die Embeddings nicht aus der Vektordatenbank entfernt werden konnten: %s';
$GLOBALS['TL_LANG']['tl_genai_documents']['stateDisabled'] = 'nicht in Vektordatenbank';
$GLOBALS['TL_LANG']['tl_genai_documents']['statePending'] = 'ausstehend';
$GLOBALS['TL_LANG']['tl_genai_documents']['stateError'] = 'Fehler';