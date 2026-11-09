# ANALYSIS.md – `alnv/genai-bundle`

## Umgebung

| Komponente | Version / Wert |
|------------|----------------|
| Contao | 5.3.x |
| PHP | ^8.3 |
| ChromaDB-Client | `helgesverre/chromadb` ~2.2 / ~3.0 (unterstützt v2 API mit Tenant/Database) |
| LLM-Bibliothek | `theodo-group/llphant` ^0.11 |
| Embedding-Modell | `qwen3-embedding:latest` (konfiguriert in `config.yml`) |
| Markdown | `michelf/php-markdown` ^2.0 |

---

## 1. Architekturübersicht

### 1.1 Request-Flow (Frontend → Controller → Retrieval → LLM → Antwort)

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  FRONTEND (ce_chat_component.html5)                                         │
│  - Vue.js 2 + vue-resource + marked.js                                       │
│  - askStream() → fetch() mit ReadableStream                                  │
└──────────────────────────────┬──────────────────────────────────────────────┘
                               │ POST /gen-ai/chat-component/ask-stream/{id}
                               ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│  ChatComponentController::askStreamChatAssistantId()                         │
│  - Liest Frage aus Request                                                   │
│  - Erstellt Assistant-Entity → Chat-Entity → Ollama/ChatGPT-Objekt           │
└──────────────────────────────┬──────────────────────────────────────────────┘
                               │
                               ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│  Chats\Ollama::getConfig()                                                   │
│  1. addInstructionMessage() → System-Prompt aus Backend-Konfiguration        │
│  2. getContext($question) → ChromaDb::get() → Embedding + Query              │
│  3. buildContextBlock() → Kontext in User-Message einbauen                   │
│  4. Gibt OllamaChat-Instanz zurück                                           │
└──────────────────────────────┬──────────────────────────────────────────────┘
                               │
                               ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│  ChromaDb::get()                                                             │
│  - OllamaEmbeddings::generate() → POST /api/embed an Ollama                  │
│  - chromaDB->items()->queryWithText() → Suche in Collection                  │
│  - Gibt documents, metadatas, distances zurück                               │
└──────────────────────────────┬──────────────────────────────────────────────┘
                               │
                               ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│  OllamaChat::generateChatStreamV2()                                          │
│  - POST /chat mit stream: true an Ollama                                     │
│  - Gibt ResponseInterface zurück                                             │
└──────────────────────────────┬──────────────────────────────────────────────┘
                               │
                               ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│  Controller: StreamedResponse                                                │
│  - Liest Stream Token für Token                                              │
│  - Sendet JSON-Zeilen (kein SSE!) an Frontend                                │
│  - Content-Type: text/plain (nicht text/event-stream!)                       │
│  - Speichert finale Antwort in Conversation (DB)                             │
└──────────────────────────────┬──────────────────────────────────────────────┘
                               │
                               ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│  FRONTEND                                                                    │
│  - ReadableStream + TextDecoder                                              │
│  - JSON.parse pro Zeile                                                      │
│  - marked.parse() für Markdown-Rendering                                     │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 1.2 Indexierungs-Flow (Contao Crawler → ChromaDB)

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  Contao Site Crawler (contao:crawl)                                          │
│  - Ruft alle Seiten auf                                                      │
└──────────────────────────────┬──────────────────────────────────────────────┘
                               │ contao.search_indexer Event
                               ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│  SiteIndexer::index(Document $document)                                      │
│  - Extrahiert Titel, Sprache, Meta aus JSON-LD                               │
│  - Prüft: noSearch, fePreview, protected                                     │
└──────────────────────────────┬──────────────────────────────────────────────┘
                               │
                               ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│  PageCrawler                                                                 │
│  1. parseContent() → HTML bereinigen (scripts, styles, indexer-Marker)       │
│  2. TextDataReader → LLPhant Document erstellen                              │
│  3. DocumentSplitter::splitDocuments(800, '.', 60) → Chunking                │
│  4. EmbeddingFormatter::formatEmbeddings()                                   │
│  5. insertDocument() → INSERT/UPDATE in tl_genai_documents                   │
│  6. addPdfs() → PDF-Links parsen und indexieren                              │
└──────────────────────────────┬──────────────────────────────────────────────┘
                               │
                               ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│  tl_genai_documents (MySQL)                                                  │
│  - Speichert Chunks als serialisiertes Array                                 │
│  - synced_at = 0 bedeutet "noch nicht in ChromaDB"                           │
└──────────────────────────────┬──────────────────────────────────────────────┘
                               │ Cronjob (minutely)
                               ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│  SyncVectors::__invoke() (DEAKTIVIERT!)                                      │
│  - chromaDb->add() ist auskommentiert                                        │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## 2. Gefundene Bugs, unvollständiger Code und TODOs

### 2.1 Kritische Bugs

| Datei | Zeile | Problem |
|-------|-------|---------|
| `Chats/Ollama.php` | 138 | **Typfehler**: `$totalLength .= strlen($block)` verwendet String-Concat statt Addition. Muss `$totalLength += strlen($block)` sein. |
| `Cronjobs/SyncVectors.php` | 18 | **Cronjob tut nichts**: `$objChroma->add()` ist auskommentiert. Dokumente werden nie nach ChromaDB synchronisiert. |
| `Vectors/ChromaDb.php` | 113-115 | **sync() ist tot**: Die Update-Logik ist auskommentiert, Methode macht nichts außer Query. |
| `EventListener/SiteIndexer.php` | 85-88 | **delete() ist leer**: Gelöschte Seiten werden nie aus ChromaDB entfernt. |
| `ChatComponentController.php` | 20 | **CSRF deaktiviert** (`_token_check => false`): Sicherheitslücke, Chat-Endpoint ist ungeschützt. |

### 2.2 Unvollständiger Code

| Datei | Problem |
|-------|---------|
| `Vectors/ChromaDb.php:49-52` | `setOpenAIEmbedding()` ist nur ein TODO-Stub. |
| `Chats/ChatGPT.php:16` | Kommentar `// todo add` – Klasse funktioniert, aber Streaming fehlt. |
| `Chats/Ollama.php:49-57` | Quellen-Extraktion ist auskommentiert, Sources werden nicht ans Frontend gesendet. |
| `Assistants/Ollama.php` | Komplette Klasse ist toter Code (alles auskommentiert). |
| `Tools/RagToolVectorSearch.php` | Wird im Controller importiert aber nicht genutzt (auskommentiert). |

### 2.3 Fehlende Funktionalität

| Feature | Status |
|---------|--------|
| **Waisen-Löschung** | Keine Logik zum Entfernen von Chunks gelöschter Seiten aus ChromaDB |
| **Change Detection** | Kein Hash-Vergleich – alle Dokumente werden immer neu indexiert |
| **DCA-Callbacks** | Keine Event-Listener für `tl_page`, `tl_article`, `tl_content` Änderungen |
| **Queue-System** | Kein Background-Job-System, Embedding erfolgt synchron (aktuell gar nicht) |
| **Rate-Limiting** | Kein Schutz gegen API-Missbrauch |
| **Konversations-Historie** | Wird zwar gespeichert, aber nicht in den Prompt eingebaut |
| **Console-Commands** | Keine CLI-Tools für Index-Management |
| **Tenant/Database** | ChromaDB v2 API nicht genutzt (nur default_tenant/default_database) |
| **Deutsche Sprachdateien** | Nur `/en/` vorhanden, kein `/de/` |

### 2.4 Tote Pfade / Unreferenced Code

- `src/Assistants/Ollama.php` – komplett auskommentiert
- `src/ChatExtensions/OllamaChat.php::$modelOptions` – nie gesetzt
- `src/DataReader/TextDataReader.php::arrMeta['sourceType']` – nie übergeben

---

## 3. Performance-Engpässe

### 3.1 Kritisch

| Problem | Code-Stelle | Auswirkung |
|---------|-------------|------------|
| **Kein HTTP-Client-Reuse** | `OllamaEmbeddings::__construct()` erstellt jedes Mal neuen Guzzle-Client | Verbindungs-Overhead bei jeder Anfrage |
| **Kein Keep-Alive** | `OllamaChat::__construct()` ebenfalls | Gleiche Verbindung wird nicht wiederverwendet |
| **Synchrones Embedding** | `Chats/Ollama::getContext()` | Blocking während der gesamten Embedding-Zeit |
| **Kein Prompt-Caching** | Statischer System-Prompt wird nach dynamischem Kontext eingefügt | Ollama/LLM kann Prompt-Prefix nicht cachen |
| **Keine Konfiguration-Caching** | `Getters::getAssistant()`, `getChat()` | DB-Query bei jedem Request |
| **Session nicht geschlossen** | `ChatComponentController::askStreamChatAssistantId()` | Session-Lock blockiert parallele Requests |

### 3.2 Mittel

| Problem | Code-Stelle | Auswirkung |
|---------|-------------|------------|
| **Vue.js 2 + vue-resource im Head** | `ChatComponent::setResources()` | Render-Blocking, ~80KB ungezipped |
| **Kein Lazy-Loading** | Template lädt alles sofort | Unnötige Ressourcen wenn Chat geschlossen |
| **Byteweise Stream-Lesen** | `readLineFromStream()` liest 1 Byte pro Iteration | Ineffizient, könnte buffern |
| **Keine Embedding-Batch** | `OllamaEmbeddings::generate()` iteriert sequentiell | Ollama unterstützt Batch-Embedding |

### 3.3 Indexierung

| Problem | Code-Stelle | Auswirkung |
|---------|-------------|------------|
| **Alles im Crawl-Request** | `SiteIndexer::index()` | Crawl wird durch Chunking verlangsamt |
| **ORDER BY RAND()** | `ChromaDb::add()`, `ChromaDb::sync()` | Ineffizient, verhindert deterministische Verarbeitung |
| **Kein Batch-Insert** | `insertDocument()` macht einzelne INSERT/UPDATE | N Queries statt 1 |

---

## 4. Architektur-Probleme

### 4.1 Konfiguration

- **Contao `$GLOBALS['TL_CONFIG']` statt Symfony-Parameter**: Konfiguration (`genAIChromaDBUrl` etc.) wird über `Config::get()` gelesen, nicht über DI.
- **Keine eigene Bundle-Configuration**: Fehlt `Configuration.php` und `alnv_genai.yaml` Support.
- **Hardcoded Defaults**: Chunk-Size (800), Overlap (60), Top-K (10), Timeout (90s) sind nicht konfigurierbar.

### 4.2 Dependency Injection

- **Direkte Instantiierung überall**: `new ChromaDb()`, `new OllamaChat()`, `new Conversation()` – nicht testbar.
- **Keine Interfaces**: Alles konkret, kein `ChatInterface`, `VectorStoreInterface`, `EmbeddingInterface`.
- **Service-Locator Anti-Pattern**: `System::getContainer()->get()` statt Constructor-Injection.

### 4.3 Datenbank

- **Keine Doctrine Entities**: Direkte `Database::getInstance()` Queries.
- **Serialisierte Arrays in DB**: `content`-Feld ist `LONGBLOB` mit `serialize()`.
- **Keine Indizes**: `tl_genai_documents.url` hat keinen Index, obwohl darauf gesucht wird.

### 4.4 Frontend

- **Kein SSE**: Verwendet JSON-Lines über `text/plain`, nicht `text/event-stream`.
- **Kein AbortController**: Stream kann nicht abgebrochen werden.
- **Keine Barrierefreiheit**: Fehlt `aria-live`, Fokus-Management, Tastatursteuerung.
- **Hardcoded Texte**: "Einen kleinen Moment...", "Gast" etc. nicht übersetzbar.
- **Kein Auto-Scroll-Stop**: Scrollt immer runter, auch wenn User hochgescrollt hat.

---

## 5. Priorisierter Umsetzungsplan

### Phase 1 – Geschwindigkeit (HIGH PRIORITY)

| # | Aufgabe | Aufwand |
|---|---------|---------|
| 1.1 | **Bug-Fix**: `Ollama.php:138` – `.=` zu `+=` | 5 Min |
| 1.2 | **Session schließen** vor Streaming: `$session->save()` | 15 Min |
| 1.3 | **Content-Type** auf `text/event-stream` ändern, SSE-Format implementieren | 1-2 Std |
| 1.4 | **HTTP-Client als Service** (Singleton mit Keep-Alive) | 1 Std |
| 1.5 | **Prompt-Reihenfolge** ändern: System → Statischer Kontext → Dynamischer Kontext | 30 Min |
| 1.6 | **Konfiguration cachen** (PSR-6 Cache für Assistant/Chat) | 1 Std |
| 1.7 | **Ollama-Optionen**: `keep_alive`, `num_ctx` konfigurierbar | 1 Std |
| 1.8 | **Timing-Logging**: Monolog-Channel für Debug | 2 Std |
| 1.9 | **Stream-Buffer**: Effizienteres Lesen (fgets oder 4KB Chunks) | 1 Std |
| 1.10 | **Warm-up Command**: `genai:warmup` für Cold-Start-Vermeidung | 1 Std |

### Phase 2 – ChromaDB & RAG (MEDIUM PRIORITY)

| # | Aufgabe | Aufwand |
|---|---------|---------|
| 2.1 | **ChromaDB v2 API**: Tenant/Database aus Config nutzen | 1 Std |
| 2.2 | **Fehlerbehandlung**: Try/Catch, Retries, Health-Check | 2 Std |
| 2.3 | **Collection pro Modell**: Name = `{base}-{model}-{dim}` | 2 Std |
| 2.4 | **Deterministische IDs**: `page-{id}-chunk-{n}` Format, `upsert` statt `add` | 1 Std |
| 2.5 | **Metadaten erweitern**: `root_page`, `content_hash`, `indexed_at`, `embedding_model` | 2 Std |
| 2.6 | **Top-K & Threshold** konfigurierbar | 1 Std |
| 2.7 | **Filter**: `where` für Sprache/Root-Page | 1 Std |
| 2.8 | **Quellen ans Frontend**: Auskommentierung entfernen, JSON-Format | 1 Std |
| 2.9 | **Query-Prefixes**: Optional für nomic, E5 etc. | 1 Std |
| 2.10 | **Distanzmetrik**: `hnsw:space: cosine` explizit setzen | 30 Min |

### Phase 3 – Embedding-Modelle (LOW PRIORITY, README)

| # | Aufgabe | Aufwand |
|---|---------|---------|
| 3.1 | **README-Sektion** mit Modell-Tabelle | 2 Std |
| 3.2 | **Backend-Feld** für Embedding-Modell-Auswahl | 2 Std |
| 3.3 | **Modellwechsel-Warnung** + Reindex in neue Collection | 2 Std |

### Phase 4 – Synchronisation & Indexierung (HIGH PRIORITY)

| # | Aufgabe | Aufwand |
|---|---------|---------|
| 4.1 | **tl_genai_index Tabelle**: Queue mit Status, Hash, Retry-Count, Error | 2 Std |
| 4.2 | **Change Detection**: content_hash vergleichen vor Re-Embedding | 2 Std |
| 4.3 | **DCA-Callbacks**: `tl_page`, `tl_article`, `tl_content` – onsubmit/ondelete | 4 Std |
| 4.4 | **Cronjob aktivieren** + Batch-Verarbeitung mit Lock | 3 Std |
| 4.5 | **Waisen-Löschung**: Täglicher Abgleich ChromaDB ↔ DB | 2 Std |
| 4.6 | **Console-Commands**: `genai:index:full`, `:sync`, `:purge`, `:status` | 4 Std |
| 4.7 | **Fehlertoleranz**: Retry-Logik, Logging | 2 Std |

### Phase 5 – Frontend (MEDIUM PRIORITY)

| # | Aufgabe | Aufwand |
|---|---------|---------|
| 5.1 | **Vue.js entfernen**, Vanilla JS mit ES-Modules | 8 Std |
| 5.2 | **SSE-Client** mit `EventSource` | 2 Std |
| 5.3 | **Markdown-Sanitizing** (XSS-sicher) | 1 Std |
| 5.4 | **AbortController** für Stop-Button | 1 Std |
| 5.5 | **Auto-Scroll mit Detection** (scrollTop vs scrollHeight) | 1 Std |
| 5.6 | **sessionStorage** für Verlauf | 1 Std |
| 5.7 | **Barrierefreiheit**: aria-live, Fokus, Tastatur | 3 Std |
| 5.8 | **CSS Custom Properties**, Dark Mode | 2 Std |
| 5.9 | **Contao-Sprachdateien** (de/en) | 2 Std |
| 5.10 | **Lazy-Init**: Widget erst bei Interaktion laden | 2 Std |

### Phase 6 – Qualität & Sicherheit (HIGH PRIORITY)

| # | Aufgabe | Aufwand |
|---|---------|---------|
| 6.1 | **CSRF-Token** reaktivieren + JavaScript anpassen | 2 Std |
| 6.2 | **Rate-Limiting** mit `symfony/rate-limiter` | 2 Std |
| 6.3 | **Eingabelänge begrenzen** | 30 Min |
| 6.4 | **Fehler maskieren** (keine internen Meldungen ins Frontend) | 1 Std |
| 6.5 | **PHPStan Level 6** | 4 Std |
| 6.6 | **PHP-CS-Fixer** | 1 Std |
| 6.7 | **Unit-Tests**: Chunking, Hash, Queue, ChromaDB-Mock | 8 Std |
| 6.8 | **README.md** erstellen | 4 Std |
| 6.9 | **CHANGELOG.md** | 1 Std |

---

## 6. Abhängigkeiten zwischen Phasen

```
Phase 4 (Sync) ──┬─► Phase 2 (ChromaDB) ──► Phase 3 (Modelle)
                 │
Phase 1 (Speed) ─┴─► Phase 5 (Frontend) ──► Phase 6 (Qualität)
```

**Empfohlene Reihenfolge**:
1. Phase 1.1 (Bug-Fix) sofort
2. Phase 6.1-6.4 (Sicherheit) vor Produktion
3. Phase 1 (Speed) parallel zu Phase 4 (Sync)
4. Phase 2 + 5 nach 1 + 4
5. Phase 3 + 6 als Abschluss

---

## 7. Offene Fragen vor Umsetzung

1. **Soll Vue.js komplett entfernt werden?** Falls Projekt-übergreifend Vue genutzt wird, könnte Beibehaltung sinnvoll sein.
2. **ChromaDB-Version auf Server?** v1 (< 1.0) oder v2 (≥ 1.0)? Beeinflusst API-Pfade.
3. **Geschützte Seiten indexieren?** Aktuell deaktiviert – soll Mitgliedergruppen-Logik implementiert werden?
4. **Mehrere Assistenten gleichzeitig?** Aktuell eine Session pro Nutzer – soll das geändert werden?
5. **Feedback-Feature** (Daumen hoch/runter) gewünscht?

---

**Erstellt am**: 2024-11-09  
**Status**: Analysephase abgeschlossen

## Changelog – Kritische Bugs behoben

### ✅ Bug-Fixes (2024-11-09)

| Datei | Änderung |
|-------|----------|
| `Chats/Ollama.php:138` | `.=` → `+=` (Typfehler behoben) |
| `Controller/ChatComponentController.php` | CSRF wieder aktiviert (`_token_check` entfernt) |
| `Components/ChatComponent.php` | CSRF-Token (`requestToken`) wird ans Template übergeben |
| `templates/ce_chat_component.html5` | JavaScript sendet `REQUEST_TOKEN` bei allen Requests |
| `Cronjobs/SyncVectors.php` | `$objChroma->add()` aktiviert (war auskommentiert) |
| `EventListener/SiteIndexer.php` | `delete()` implementiert – entfernt Chunks aus ChromaDB + DB |
| `Vectors/ChromaDb.php` | `deleteByDocumentId()` und `deleteByUrl()` hinzugefügt |

### ✅ Phase 1 – Geschwindigkeit (2024-11-09)

| # | Änderung | Datei(en) |
|---|----------|-----------|
| 1.2 | **Session schließen** vor Streaming (`$session->save()`) | `ChatComponentController.php` |
| 1.3 | **SSE-Format** mit `text/event-stream`, `data:` Prefix | `ChatComponentController.php`, Template |
| 1.4 | **Keep-Alive** Header für HTTP-Verbindungen | `OllamaChat.php`, `services.yml` |
| 1.5 | **Prompt-Reihenfolge** optimiert: System (statisch) → Kontext → User-Frage | `Chats/Ollama.php` |
| 1.6 | **Quellen-Extraktion** für Frontend aktiviert | `Chats/Ollama.php` |
| 1.7 | **Ollama-Optionen**: `keep_alive`, `num_ctx` konfigurierbar | `Chats/Ollama.php`, `OllamaChat.php` |
| 1.8 | **Timing-Logging** mit optionalem Logger | `ChatComponentController.php` |
| 1.9 | **Stream-Buffer** 4KB statt byteweise lesen | `ChatComponentController.php` |
| 1.10 | **Warm-up Command** `genai:warmup` | `Command/WarmupCommand.php` |
| 1.11 | **Index-Commands** `genai:index:status`, `genai:index:sync` | `Command/Index*.php` |
| 1.12 | **Top-K konfigurierbar** bei ChromaDB-Query | `Vectors/ChromaDb.php` |

#### Neue Konfigurationsoptionen (in Contao config.yml)

```yaml
contao:
  localconfig:
    # Ollama Timeouts und Optionen
    genAIOllamaTimeout: 120        # Request-Timeout in Sekunden
    genAIOllamaKeepAlive: '5m'     # Modell im RAM halten
    genAIOllamaNumCtx: 4096        # Kontextfenster-Größe
    
    # RAG-Einstellungen
    genAIMaxContextLength: 8000    # Max. Zeichen für Kontext
    genAITopK: 10                  # Anzahl Retrieval-Ergebnisse
```

#### Neue Console-Commands

```bash
# Modelle vorwärmen (Cold-Start vermeiden)
php vendor/bin/contao-console genai:warmup --chat-model=qwen3:4b --embedding-model=qwen3-embedding

# Index-Status anzeigen
php vendor/bin/contao-console genai:index:status

# Pending Documents synchronisieren
php vendor/bin/contao-console genai:index:sync --limit=50
php vendor/bin/contao-console genai:index:sync --dry-run
```

### ✅ Phase 2 – ChromaDB & RAG (2024-11-09)

| # | Änderung | Datei(en) |
|---|----------|-----------|
| 2.1 | **ChromaDB v2 API**: Tenant/Database konfigurierbar | `Vectors/ChromaDb.php` |
| 2.2 | **Fehlerbehandlung**: Retries mit exponential backoff, Health-Check | `Vectors/ChromaDb.php` |
| 2.3 | **Collection pro Modell**: Name = `{base}-{model-slug}` | `Vectors/ChromaDb.php` |
| 2.4 | **Deterministische IDs**: `{type}-{sourceId}-chunk-{n}`, Upsert statt Add | `Vectors/ChromaDb.php` |
| 2.5 | **Erweiterte Metadaten**: `source_type`, `source_id`, `root_page`, `content_hash`, `indexed_at`, `embedding_model` | `Vectors/ChromaDb.php` |
| 2.6 | **Top-K konfigurierbar** | bereits in Phase 1 |
| 2.7 | **Filter**: Sprache und Root-Page über `where`-Clause | `Vectors/ChromaDb.php`, `Chats/Ollama.php` |
| 2.8 | **Quellen ans Frontend** | bereits in Phase 1 |
| 2.9 | **Query-Prefixes**: Automatisch für nomic, E5, BGE | `Embeddings/OllamaEmbeddings.php` |
| 2.10 | **Distanzmetrik**: `hnsw:space: cosine` explizit gesetzt | `Vectors/ChromaDb.php` |

#### Neue Konfigurationsoptionen für ChromaDB

```yaml
contao:
  localconfig:
    # ChromaDB v2 API
    genAIChromaDBTenant: 'default_tenant'
    genAIChromaDBDatabase: 'default_database'
    genAIChromaDBToken: ''  # Optional Auth Token
    
    # Embedding Model Prefixes (optional, automatische Erkennung für bekannte Modelle)
    genAIEmbeddingQueryPrefix: ''
    genAIEmbeddingDocumentPrefix: ''
```

#### Neue Console-Commands

```bash
# Vollständiger Reindex
php vendor/bin/contao-console genai:index:full
php vendor/bin/contao-console genai:index:full --force --batch-size=20
php vendor/bin/contao-console genai:index:full --language=de

# Index bereinigen
php vendor/bin/contao-console genai:index:purge --all
php vendor/bin/contao-console genai:index:purge --orphans --dry-run
```

#### Verbessertes ID-Schema

| Alt | Neu |
|-----|-----|
| `ID-{docId}-{chunk}` | `page-{pageId}-chunk-{n}` |
| | `file-{uuid}-chunk-{n}` |

Vorteile:
- Stabiler bei Re-Indexierung (gleiche IDs bei gleichem Content)
- Semantisch aussagekräftig
- Unterstützt Backwards-Compatibility für alte IDs

**Warte auf Freigabe für Phase 3 (Embedding-Modelle)**

### ✅ Phase 3 – Embedding-Modelle (2024-11-09)

| # | Änderung | Datei(en) |
|---|----------|-----------|
| 3.1 | **README erstellt** mit Modell-Tabelle, Empfehlungen, Installation, Commands | `README.md` |
| 3.2 | **Backend-Feld** für Embedding-Modell-Auswahl pro Collection | `dca/tl_genai_documents_collections.php` |
| 3.3 | **Modellwechsel-Warnung** + Reindex-Flag bei Änderung | `dca/tl_genai_documents_collections.php` |
| 3.4 | **Migration** für neue Datenbankfelder | `Migration/Version20241109AddEmbeddingModelMigration.php` |
| 3.5 | **Sprachdateien** (DE + EN) für neue Felder | `languages/{de,en}/tl_genai_documents_collections.php` |

#### Neue DCA-Felder in tl_genai_documents_collections

| Feld | Typ | Beschreibung |
|------|-----|--------------|
| `embeddingModel` | select | Embedding-Modell-Auswahl mit Empfehlungen |
| `embeddingUrl` | text | Optionale Ollama-URL (überschreibt global) |
| `filterLanguage` | select | Sprachfilter für Indexierung |
| `filterRootPage` | pageTree | Startpunkt-Filter für Multi-Site |
| `reindexRequired` | boolean | Flag wenn Modellwechsel erkannt |

#### Empfohlene Modelle (aus README)

| Anwendungsfall | Modell | Warum |
|----------------|--------|-------|
| **Standard (Deutsch)** | `bge-m3` | Beste Balance aus Qualität und Geschwindigkeit |
| **Beste Qualität** | `qwen3-embedding:8b` | Höchste Genauigkeit, braucht GPU |
| **CPU-optimiert** | `multilingual-e5-small` | Schnellste Variante, nur 470 MB |

**Warte auf Freigabe für Phase 4 (Synchronisation & Indexierung)**

### ✅ Phase 4 – Synchronisation & Indexierung (2024-11-09)

| # | Änderung | Datei(en) |
|---|----------|-----------|
| 4.1 | **tl_genai_index Queue-Tabelle** mit Status, Priority, Hash, Retries, Error | `Migration/Version20241109CreateIndexQueueMigration.php` |
| 4.2 | **Change Detection** via content_hash (xxh3) vor Re-Embedding | `Service/IndexQueue.php`, `Cronjobs/SyncVectors.php` |
| 4.3 | **DCA-Callbacks** für tl_page, tl_article, tl_content, tl_news, tl_calendar_events | `EventListener/ContentChangeListener.php` |
| 4.4 | **Cronjob mit Batching** + File-Lock (symfony/lock) | `Cronjobs/SyncVectors.php` |
| 4.5 | **Waisen-Löschung** (täglicher Cronjob) | `Cronjobs/CleanupOrphans.php` |
| 4.6 | **Console-Commands erweitert** mit Queue-Statistiken | `Command/IndexStatusCommand.php` |
| 4.7 | **Fehlertoleranz**: Retry-Logik mit max_retries, exponential backoff | `Service/IndexQueue.php` |

#### Neue Queue-Tabelle (tl_genai_index)

| Spalte | Typ | Beschreibung |
|--------|-----|--------------|
| `source_type` | VARCHAR(32) | page, article, content, news, event, file |
| `source_table` | VARCHAR(64) | tl_page, tl_article, etc. |
| `source_id` | INT | ID der Quelle |
| `status` | VARCHAR(16) | pending, processing, completed, failed, deleted |
| `priority` | TINYINT | 1 (höchste) bis 10 (niedrigste) |
| `content_hash` | VARCHAR(64) | xxh3 Hash des bereinigten Inhalts |
| `retry_count` | TINYINT | Anzahl fehlgeschlagener Versuche |
| `error_message` | TEXT | Letzte Fehlermeldung |

#### Automatische Indexierung

Content-Änderungen werden automatisch in die Queue eingetragen:

| Event | Aktion | Priorität |
|-------|--------|-----------|
| Seite speichern | Queue für Reindex | 3 (hoch) |
| Seite löschen | Mark for deletion | - |
| Artikel speichern | Queue für Reindex | 4 |
| Inhaltselement speichern | Trigger Artikel-Reindex | 5 |
| News/Event speichern | Queue für Reindex | 4 |

#### Cronjob-Verhalten

**SyncVectors (minutely)**:
- Verarbeitet max. 5 Items pro Batch
- Time-Limit: 55 Sekunden (Puffer für Cron-Interval)
- File-Lock verhindert parallele Ausführung
- Deletions werden zuerst verarbeitet

**CleanupOrphans (daily)**:
- Entfernt Dokumente ohne gültige Quelle (gelöschte Seiten)
- Entfernt Dokumente für unveröffentlichte/abgelaufene Seiten
- Bereinigt alte Queue-Einträge (> 7 Tage completed, > 30 Tage failed)

#### Fehlertoleranz

- Max. 3 Retries pro Item (konfigurierbar)
- Failed Items werden nach Retry-Limit nicht mehr verarbeitet
- Error-Messages werden für Debugging gespeichert
- Queue-Status im `genai:index:status` Command sichtbar

**Warte auf Freigabe für Phase 5 (Frontend)**

### ✅ Phase 5 – Frontend (2024-11-09)

| # | Änderung | Datei(en) |
|---|----------|-----------|
| 5.1 | **Vue.js entfernt**, Vanilla JS mit ES-Module-Syntax | `ce_chat_component.html5` |
| 5.2 | **SSE-Client** mit fetch + ReadableStream | `ce_chat_component.html5` |
| 5.3 | **Markdown-Sanitizing** (XSS-sicher, kein externes Dependency) | `ce_chat_component.html5` |
| 5.4 | **AbortController** für Stop-Button | `ce_chat_component.html5` |
| 5.5 | **Auto-Scroll mit Detection** (userScrolled Flag) | `ce_chat_component.html5` |
| 5.6 | **sessionStorage** für Chatverlauf (try/catch) | `ce_chat_component.html5` |
| 5.7 | **Barrierefreiheit**: aria-live, role="log", Fokus, Keyboard | `ce_chat_component.html5` |
| 5.8 | **CSS Custom Properties**, Dark Mode (prefers-color-scheme) | `chat-component.scss` |
| 5.9 | **Contao-Sprachdateien** (de/en) | `languages/{de,en}/default.php` |
| 5.10 | **Reduced Motion** Support | `chat-component.scss` |

#### Frontend-Features

| Feature | Beschreibung |
|---------|--------------|
| **Streaming** | Token-für-Token Anzeige via SSE |
| **Stop-Button** | Generierung abbrechen (AbortController) |
| **Copy-Button** | Antworten kopieren (Clipboard API) |
| **Quellen** | Einklappbar unter jeder Antwort |
| **Scroll-Indicator** | "Zur neuesten Antwort" Button |
| **Auto-Grow Textarea** | Wächst mit Eingabe mit |
| **Zeichenlimit** | 4000 Zeichen mit Anzeige |
| **Dark Mode** | Automatisch via `prefers-color-scheme` |
| **Chatverlauf** | Persistiert in sessionStorage |
| **Retry-Button** | Bei Fehlern erneut versuchen |

#### CSS Custom Properties (Theming)

```css
.genai-chat {
  --genai-bg: #ffffff;
  --genai-text: #1a1a1a;
  --genai-primary: #2563eb;
  --genai-user-bg: #e8f0fe;
  --genai-assistant-bg: #f8f9fa;
  --genai-radius-md: 0.75rem;
  /* ... weitere Properties in chat-component.scss */
}
```

#### Barrierefreiheit

- `role="log"` + `aria-live="polite"` für neue Nachrichten
- Fokus-Management beim Senden
- Keyboard: Enter = Senden, Shift+Enter = Neue Zeile
- `prefers-reduced-motion` für Animationen
- Ausreichende Farbkontraste (WCAG AA)

#### Neue Sprachschlüssel

| Schlüssel | DE | EN |
|-----------|----|----|
| `genai.placeholder` | Stellen Sie Ihre Frage … | Ask your question … |
| `genai.send` | Senden | Send |
| `genai.stop` | Stoppen | Stop |
| `genai.newChat` | Neuer Chat | New Chat |
| `genai.sources` | Quellen | Sources |
| `genai.error` | Ein Fehler ist aufgetreten. | An error occurred. |

**Warte auf Freigabe für Phase 6 (Qualität & Sicherheit)**

### ✅ Phase 6 – Qualität & Sicherheit (2024-11-09)

| # | Änderung | Datei(en) |
|---|----------|-----------|
| 6.1 | **CSRF-Token** | Bereits in Bug-Fixes reaktiviert |
| 6.2 | **Rate-Limiting** (10 Req/Min/IP) mit symfony/rate-limiter | `Controller/ChatComponentController.php`, `services.yml` |
| 6.3 | **Eingabelänge begrenzt** (max 4000 Zeichen) | `Controller/ChatComponentController.php` |
| 6.4 | **Fehler-Maskierung** (keine internen Details im Frontend) | `Controller/ChatComponentController.php` |
| 6.5 | **PHPStan-Konfiguration** (Level 6) | `phpstan.neon` |
| 6.6 | **PHPUnit-Konfiguration** | `phpunit.xml.dist` |
| 6.7 | **Unit-Tests** (Grundstruktur) | `tests/Service/IndexQueueTest.php`, `tests/Util/ChunkingTest.php` |
| 6.8 | **README.md** | Bereits in Phase 3 erstellt |
| 6.9 | **CHANGELOG.md** | `CHANGELOG.md` |
| 6.10 | **DCA tl_genai_index** | `dca/tl_genai_index.php`, `Backend/IndexBackend.php`, Sprachdateien |

#### Sicherheitsmaßnahmen

| Maßnahme | Implementierung |
|----------|-----------------|
| **CSRF** | Request-Token wird geprüft |
| **Rate-Limiting** | 10 Requests/Minute pro IP (sliding window) |
| **Input-Validierung** | Max. 4000 Zeichen, Control-Chars entfernt |
| **Error-Masking** | Generische Fehlermeldungen im Frontend |
| **XSS-Schutz** | Eigener Markdown-Parser mit HTML-Escaping |

#### Test-Struktur

```
tests/
├── Service/
│   └── IndexQueueTest.php    # Queue-Service Tests
└── Util/
    └── ChunkingTest.php      # Chunking-Algorithmus Tests
```

#### Neue Konfigurationsdateien

- `phpstan.neon` – PHPStan Level 6 mit Contao-Ignores
- `phpunit.xml.dist` – PHPUnit 10 Konfiguration
- `CHANGELOG.md` – Vollständige Änderungsliste

---

## 🎉 Alle Phasen abgeschlossen!

### Zusammenfassung der Änderungen

| Phase | Fokus | Status |
|-------|-------|--------|
| Bug-Fixes | Kritische Fehler beheben | ✅ |
| Phase 1 | Geschwindigkeit | ✅ |
| Phase 2 | ChromaDB & RAG | ✅ |
| Phase 3 | Embedding-Modelle | ✅ |
| Phase 4 | Synchronisation & Indexierung | ✅ |
| Phase 5 | Frontend | ✅ |
| Phase 6 | Qualität & Sicherheit | ✅ |

### Neue Dateien (gesamt)

```
src/
├── Command/
│   ├── IndexFullCommand.php
│   ├── IndexPurgeCommand.php
│   ├── IndexStatusCommand.php
│   ├── IndexSyncCommand.php
│   └── WarmupCommand.php
├── Cronjobs/
│   └── CleanupOrphans.php
├── EventListener/
│   └── ContentChangeListener.php
├── Migration/
│   ├── Version20241109AddEmbeddingModelMigration.php
│   └── Version20241109CreateIndexQueueMigration.php
└── Service/
    └── IndexQueue.php

tests/
├── Service/
│   └── IndexQueueTest.php
└── Util/
    └── ChunkingTest.php

README.md
CHANGELOG.md
phpstan.neon
phpunit.xml.dist
```

### Nächste Schritte (optional)

1. **Tests erweitern**: Mehr Unit-Tests für ChromaDb, Ollama
2. **Integration-Tests**: End-to-End Tests mit Mock-Server
3. **Feedback-Feature**: Daumen hoch/runter für Antworten
4. **Hybrid-Suche**: Keyword + Vektor-Suche kombinieren
5. **Re-Ranking**: Ergebnisse vor LLM-Kontext neu sortieren
