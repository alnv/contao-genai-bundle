# Changelog

Alle wichtigen Änderungen an diesem Bundle werden in dieser Datei dokumentiert.

Das Format basiert auf [Keep a Changelog](https://keepachangelog.com/de/1.0.0/)
und dieses Projekt verwendet [Semantic Versioning](https://semver.org/lang/de/).

## [Unreleased]

### Hinzugefügt

#### Inhaltselement GenAI Chat (2026-11-09)
- Neues Contao-Inhaltselement `genai_chat` in Kategorie "GenAI"
- Auswahl des Assistenten direkt im Backend
- Backend-Vorschau zeigt Assistent-ID
- Template `ce_genai_chat.html5` für Frontend-Ausgabe
- Deutsche und englische Übersetzungen

#### Phase 1 – Geschwindigkeit
- SSE (Server-Sent Events) Streaming für Echtzeit-Antworten
- Session-Schließung vor Streaming für parallele Requests
- 4KB Stream-Buffer für effizienteres Lesen
- HTTP Keep-Alive Header für Verbindungswiederverwendung
- Prompt-Reihenfolge optimiert (System → Kontext → Frage) für LLM-Caching
- Ollama-Optionen konfigurierbar (`keep_alive`, `num_ctx`)
- Timing-Logging über Monolog-Channel
- Console-Command `genai:warmup` für Cold-Start-Vermeidung
- Console-Command `genai:index:status` für Index-Statistiken
- Console-Command `genai:index:sync` für manuelle Synchronisation
- Konfigurierbare Top-K Einstellung für Retrieval

#### Phase 2 – ChromaDB & RAG
- ChromaDB v2 API Unterstützung (Tenant/Database)
- Retry-Logik mit exponential backoff für ChromaDB-Operationen
- Health-Check Methode für ChromaDB
- Collection pro Embedding-Modell (verhindert Dimension-Mismatch)
- Deterministische Chunk-IDs (`{type}-{sourceId}-chunk-{n}`)
- Erweiterte Metadaten (source_type, content_hash, indexed_at, embedding_model)
- Sprach- und Root-Page-Filter für Multisite-Setups
- Query-Prefixes für nomic, E5, BGE Modelle (automatisch erkannt)
- Cosine-Distanzmetrik explizit gesetzt
- Console-Command `genai:index:full` für vollständigen Reindex
- Console-Command `genai:index:purge` für Index-Bereinigung

#### Phase 3 – Embedding-Modelle
- README.md mit Modell-Empfehlungen für deutschsprachige Websites
- Backend-Feld für Embedding-Modell-Auswahl pro Collection
- Modellwechsel-Erkennung mit Warnung und Reindex-Flag
- Migration für neue Collection-Felder
- Sprachdateien (DE/EN) für Collection-DCA

#### Phase 4 – Synchronisation & Indexierung
- Queue-Tabelle `tl_genai_index` für asynchrone Indexierung
- Change Detection via content_hash (xxh3)
- DCA-Callbacks für tl_page, tl_article, tl_content, tl_news, tl_calendar_events
- Cronjob mit Batching, Time-Limit und File-Lock
- Täglicher Cleanup-Cronjob für Waisen-Löschung
- IndexQueue Service für Queue-Management
- Fehlertoleranz mit Retry-Logik (max 3 Versuche)

#### Phase 5 – Frontend
- Komplettes Rewrite in Vanilla JS (Vue.js entfernt)
- SSE Streaming mit fetch + ReadableStream
- Eigener Markdown-Parser (XSS-sicher, keine externe Dependency)
- AbortController für Stop-Button
- Auto-Scroll mit Benutzer-Erkennung
- sessionStorage für Chatverlauf
- Barrierefreiheit (aria-live, role="log", Keyboard-Navigation)
- CSS Custom Properties für einfaches Theming
- Dark Mode via prefers-color-scheme
- Reduced Motion Support
- Quellen einklappbar unter jeder Antwort
- Copy-Button für Antworten
- Auto-Grow Textarea mit Zeichenlimit-Anzeige
- "Zur neuesten Antwort" Button
- Sprachdateien (DE/EN) für Frontend

#### Phase 6 – Qualität & Sicherheit
- Rate-Limiting (10 Requests/Minute pro IP)
- Eingabelänge begrenzt (max. 4000 Zeichen)
- Fehler-Maskierung (keine internen Details im Frontend)
- Input-Sanitization (Control-Chars entfernt)
- CHANGELOG.md erstellt

#### Collection-Filter für Assistenten
- Neues Feld `documentCollections` in tl_genai_assistants
- Multi-Select für Dokument-Collections pro Assistent
- RAG nutzt nur ausgewählte Collections (wenn konfiguriert)
- `collection_id` in ChromaDB-Metadaten gespeichert
- Filter mit `$in` Operator für mehrere Collections

#### Per-Collection Embedding-Einstellungen
- `IndexFullCommand` gruppiert nach Collection und nutzt Collection-spezifische Modelle/URLs
- `SyncVectors` Cronjob cached ChromaDb-Instanzen pro Collection
- `Ollama.php` liest Embedding-Config aus ausgewählten Collections
- Fallback auf globale Konfiguration wenn Collection keine eigenen Einstellungen hat
- Neue Option `--collection` für IndexFullCommand

#### Konfigurationsbereinigung (2026-11-09)
- Embedding-Konfiguration vollständig zentralisiert (nur noch globale Config)
- `embeddingModel` und `embeddingUrl` aus `tl_genai_documents_collections` entfernt
- Migration entfernt automatisch die obsoleten Spalten
- Alle Commands, Services und Cronjobs nutzen nur noch `genAIChromaDBOllamaUrl` und `genAIChromaDBOllamaModel`

#### PageCrawler Optimierung
- URL-Normalisierung verhindert doppelte Indexierung
- Duplikaterkennung innerhalb eines Requests via statischem Cache
- Content-Hash für Change-Detection
- Verbesserte Fehlerbehandlung und Logging
- Skip externe PDFs und Dateien > 50MB
- Bereinigung von nav, noscript und style Tags
- Root-Page-ID wird korrekt gesetzt
- Collection-ID dynamisch ermittelt

#### Markdown-Rendering mit marked.js
- marked.js v18.1.2 integriert (minified, ESM)
- DOMPurify v3.2.2 für XSS-Schutz
- Fallback auf einfachen Parser wenn Libraries nicht laden
- Vollständige Markdown-Unterstützung:
  - Überschriften (h1-h6)
  - Fett, Kursiv, Durchgestrichen
  - Geordnete und ungeordnete Listen
  - Code-Blöcke mit Syntax-Klassen
  - Inline-Code
  - Links mit target="_blank" für externe URLs
  - Blockquotes
  - Tabellen
  - Horizontale Linien

#### ChatGPT/OpenAI Integration (2026-11-09)
- `ChatGPT.php` komplett überarbeitet analog zu `Ollama.php`
- Neue `OpenAIChat.php` ChatExtension für Streaming
- SSE-Streaming für OpenAI-API (`generateChatStreamV2()`)
- `getConfig()` und `getConversation()` Methoden wie bei Ollama
- Quellen-Extraktion und Kontext-Aufbau identisch zu Ollama
- Collection-Filter und Root-Page-Filter
- Globale Embedding-Konfiguration (keine separaten OpenAI-Embeddings)
- Controller erkennt automatisch OpenAI vs. Ollama Streaming-Format

### Entfernt

- `filterLanguage` Feld aus tl_genai_documents_collections (Startpunkt bestimmt Sprache)
- `language` Filter aus ChromaDB-Metadaten und Queries
- `--language` Option aus IndexFullCommand
- Sprache wird jetzt über `root_page` (Startpunkt) gesteuert
- `embeddingModel` und `embeddingUrl` Felder aus tl_genai_documents_collections (jetzt zentrale Config)
- Per-Collection Embedding-Konfiguration (vereinfacht auf globale Config)

### Behoben

#### Kritische Bugs
- `Ollama.php:138`: Typfehler `.=` statt `+=` behoben
- `ChatComponentController.php`: CSRF-Token reaktiviert (war deaktiviert)
- `SyncVectors.php`: `$objChroma->add()` aktiviert (war auskommentiert)
- `SiteIndexer.php`: `delete()` Methode implementiert (war leer)

### Geändert

- `ChatComponentController`: Komplett überarbeitet für SSE und Sicherheit
- `Vectors/ChromaDb.php`: Komplett überarbeitet für v2 API und Upsert
- `Embeddings/OllamaEmbeddings.php`: Query/Document Prefixes hinzugefügt
- `Chats/Ollama.php`: Prompt-Struktur und Quellen-Extraktion verbessert
- `ChatExtensions/OllamaChat.php`: Keep-Alive und Model-Options
- `Cronjobs/SyncVectors.php`: Queue-basierte Verarbeitung mit Lock
- Template `ce_chat_component.html5`: Komplettes Rewrite in Vanilla JS
- SCSS `chat-component.scss`: CSS Custom Properties und Dark Mode

### Entfernt

- Vue.js Dependency im Frontend
- Einfacher Regex-basierter Markdown-Parser (ersetzt durch marked.js + DOMPurify)

## [1.0.0] - Ursprüngliche Version

### Hinzugefügt
- Grundlegende Chat-Funktionalität mit Ollama
- ChromaDB Integration für RAG
- Contao Content Element
- Backend-Konfiguration für Assistenten und Chats
