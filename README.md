# GenAI Bundle für Contao

RAG-Chatbot für Contao CMS mit lokalen LLMs (Ollama), OpenAI/ChatGPT und ChromaDB Vektorsuche.

## Features

- 🤖 **Lokale LLMs** via Ollama (keine Cloud-Abhängigkeit, Datenschutz-konform)
- 🌐 **OpenAI/ChatGPT** Unterstützung mit Streaming
- 🔍 **RAG (Retrieval Augmented Generation)** mit ChromaDB
- ⚡ **Streaming-Antworten** via Server-Sent Events (SSE)
- 🌐 **Multisite-fähig** mit Root-Page-Filterung
- 📄 **Automatische Indexierung** von Seiten und Dateien
- 🎨 **Anpassbares Widget** für das Frontend
- 🔒 **Sicherheit**: CSRF-Schutz, Rate-Limiting, XSS-Prävention

## Voraussetzungen

- Contao 5.3+
- PHP 8.2+
- [Ollama](https://ollama.ai/) für LLM und Embeddings (oder OpenAI API)
- [ChromaDB](https://www.trychroma.com/) für Vektorsuche

## Installation

```bash
composer require alnv/genai-bundle
```

Nach der Installation:

```bash
# Datenbank aktualisieren
php vendor/bin/contao-console contao:migrate

# Cache leeren
php vendor/bin/contao-console cache:clear
```

---

## Ollama Modelfile

Mit einem **Modelfile** können Sie ein bestehendes Ollama-Modell anpassen und optimieren. Das ist besonders nützlich für:

### Vorteile eines Modelfile

| Vorteil | Beschreibung |
|---------|--------------|
| **Konsistentes Verhalten** | System-Prompt ist fest im Modell verankert |
| **Schnellerer Start** | Kein System-Prompt bei jeder Anfrage nötig |
| **Angepasste Parameter** | Temperatur, Kontext-Größe, etc. vordefiniert |
| **Einfache Verteilung** | Modell mit `ollama push` teilen |
| **Persona-Definition** | Feste Rolle/Persönlichkeit des Assistenten |

### Beispiel: Deutschsprachiger Support-Assistent

Erstellen Sie eine Datei `Modelfile`:

```dockerfile
# Basis-Modell (empfohlen für Deutsch)
FROM qwen3:4b

# System-Prompt für den Assistenten
SYSTEM """
Du bist ein freundlicher und kompetenter Kundenservice-Assistent für ein deutsches Unternehmen.

Deine Aufgaben:
- Beantworte Fragen basierend auf den bereitgestellten Dokumenten
- Antworte immer auf Deutsch, auch wenn die Frage auf Englisch gestellt wird
- Sei höflich, präzise und hilfreich
- Wenn du etwas nicht weißt, sage es ehrlich
- Verweise auf die Quellen, wenn du Informationen aus Dokumenten nutzt

Formatierung:
- Nutze Markdown für strukturierte Antworten
- Verwende Listen für mehrere Punkte
- Hebe wichtige Begriffe **fett** hervor
"""

# Parameter-Optimierung
PARAMETER temperature 0.7
PARAMETER num_ctx 8192
PARAMETER top_p 0.9
PARAMETER repeat_penalty 1.1

# Template für Chat-Format (optional)
TEMPLATE """
{{- if .System }}
<|im_start|>system
{{ .System }}<|im_end|>
{{- end }}
{{- range .Messages }}
<|im_start|>{{ .Role }}
{{ .Content }}<|im_end|>
{{- end }}
<|im_start|>assistant
"""
```

### Modell erstellen und verwenden

```bash
# Modell aus Modelfile erstellen
ollama create mein-assistent -f Modelfile

# Modell testen
ollama run mein-assistent "Hallo, wer bist du?"

# Modell in Contao verwenden
# → Im Backend unter GenAI > Chats das Modell "mein-assistent" eintragen
```

### Weitere Modelfile-Beispiele

#### Technischer Support

```dockerfile
FROM qwen3:4b

SYSTEM """
Du bist ein technischer Support-Spezialist. 
Erkläre komplexe Sachverhalte verständlich.
Gib bei Fehlern konkrete Lösungsschritte an.
Frage nach, wenn Informationen fehlen.
"""

PARAMETER temperature 0.5
PARAMETER num_ctx 8192
```

#### Kreativer Assistent

```dockerfile
FROM qwen3:4b

SYSTEM """
Du bist ein kreativer Schreibassistent.
Hilf beim Verfassen von Texten, E-Mails und Inhalten.
Passe deinen Stil an die gewünschte Zielgruppe an.
"""

PARAMETER temperature 0.9
PARAMETER num_ctx 4096
```

### Nützliche Parameter

| Parameter | Standard | Beschreibung |
|-----------|----------|--------------|
| `temperature` | 0.8 | Kreativität (0.0 = deterministisch, 1.0 = kreativ) |
| `num_ctx` | 2048 | Kontextfenster-Größe in Tokens |
| `top_p` | 0.9 | Nucleus Sampling |
| `top_k` | 40 | Top-K Sampling |
| `repeat_penalty` | 1.1 | Vermeidung von Wiederholungen |
| `num_predict` | -1 | Max. Tokens in Antwort (-1 = unbegrenzt) |

---

## Schnellstart: Backend-Konfiguration

### Schritt 1: Chat erstellen

1. Navigieren Sie zu **GenAI → Chats**
2. Klicken Sie auf **Neu**
3. Füllen Sie die Felder aus:

| Feld | Beispielwert | Beschreibung |
|------|--------------|--------------|
| Name | `Ollama Qwen3` | Interner Name |
| Typ | `ollama` | Provider-Typ |
| URL | `http://localhost:11434` | Ollama API URL |
| Modell | `qwen3:4b` | LLM-Modell |

4. **Speichern**

### Schritt 2: Dokument-Collection erstellen

1. Navigieren Sie zu **GenAI → Dokumente**
2. Klicken Sie auf **Neu** (Collection)
3. Füllen Sie die Felder aus:

| Feld | Beispielwert | Beschreibung |
|------|--------------|--------------|
| Titel | `Website-Inhalte` | Name der Collection |
| Root-Page | `Meine Website [de]` | Optional: Nur diese Seiten indexieren |

4. **Speichern**
5. Die Dokumente werden automatisch beim nächsten Cronjob indexiert

### Schritt 3: Assistenten erstellen

1. Navigieren Sie zu **GenAI → Assistenten**
2. Klicken Sie auf **Neu**
3. Füllen Sie die Felder aus:

| Feld | Beispielwert |
|------|--------------|
| Name | `Support-Assistent` |
| Assistentenname | `KI-Assistent` |
| Begrüßungstext | `Hallo! Wie kann ich Ihnen helfen?` |
| Instruktionen | `Du bist ein hilfreicher Assistent. Antworte auf Deutsch.` |
| Dokument-Collections | ☑️ `Website-Inhalte` |
| Chat | `Ollama Qwen3` |

4. **Speichern**

### Schritt 4: Inhaltselement anlegen

1. Bearbeiten Sie einen **Artikel** in Contao
2. Fügen Sie ein neues **Inhaltselement** hinzu
3. Wählen Sie unter **GenAI** das Element **GenAI Chat**
4. Konfigurieren Sie:

| Feld | Beispielwert |
|------|--------------|
| Überschrift | `Fragen Sie unseren Assistenten` |
| Assistent | `Support-Assistent` |
| Text | `Ich beantworte gerne Ihre Fragen.` |

5. **Speichern und schließen**

### Schritt 5: Indexierung starten

```bash
# Vollständiger Index aller Seiten
php vendor/bin/contao-console genai:index:full

# Status prüfen
php vendor/bin/contao-console genai:index:status
```

**Fertig!** Das Chat-Widget ist jetzt auf der Seite sichtbar und einsatzbereit.

---

## Inhaltselement: GenAI Chat

Das Bundle stellt ein eigenes Contao-Inhaltselement bereit.

### Im Backend

1. Artikel bearbeiten → Inhaltselement hinzufügen
2. Kategorie **GenAI** → **GenAI Chat** auswählen
3. Assistent wählen und optional Überschrift/Text anpassen

### In eigenen Templates

```php
<?php
// In einem Contao-Template oder Modul
use Alnv\GenaiBundle\Components\ChatComponent;

echo (new ChatComponent(1, [
    'headline' => 'Ihr KI-Assistent',
    'text' => 'Stellen Sie mir Ihre Fragen!'
]))->generate();
?>
```

### Parameter

| Parameter | Typ | Beschreibung |
|-----------|-----|--------------|
| `$assistantId` | int | ID des Assistenten aus dem Backend |
| `headline` | string | Überschrift über dem Chat-Widget |
| `text` | string | Beschreibungstext unter der Überschrift |

### Beispiel mit dynamischer Assistent-Auswahl

```php
<?php
// Assistent je nach Sprache
$assistantId = $GLOBALS['TL_LANGUAGE'] === 'en' ? 2 : 1;

echo (new \Alnv\GenaiBundle\Components\ChatComponent($assistantId, [
    'headline' => $GLOBALS['TL_LANGUAGE'] === 'en' 
        ? 'Your AI Assistant' 
        : 'Ihr KI-Assistent',
    'text' => ''
]))->generate();
?>
```

---

## Konfiguration

### Alle Config-Optionen

Die Konfiguration erfolgt in `config/config.yml` oder `system/config/localconfig.php`:

#### Ollama Server

| Option | Standard | Beschreibung |
|--------|----------|--------------|
| `genAIOllamaUrl` | `http://localhost:11434` | Ollama API URL |
| `genAIOllamaTimeout` | `120` | Timeout in Sekunden |
| `genAIOllamaKeepAlive` | `5m` | Modell im RAM halten |
| `genAIOllamaNumCtx` | `4096` | Kontextfenster-Größe |
| `genAIOllamaEmbeddingTimeout` | `120` | Embedding-Timeout |

#### ChromaDB Server

| Option | Standard | Beschreibung |
|--------|----------|--------------|
| `genAIChromaDBUrl` | `http://localhost:8000` | ChromaDB API URL |
| `genAIChromaDBPort` | `8000` | ChromaDB Port |
| `genAIChromaDBCollectionName` | `genai` | Basis-Collection-Name |
| `genAIChromaDBTenant` | `default_tenant` | ChromaDB Tenant |
| `genAIChromaDBDatabase` | `default_database` | ChromaDB Database |
| `genAIChromaDBToken` | – | Optional: Auth-Token |

#### Embedding (Ollama)

| Option | Standard | Beschreibung |
|--------|----------|--------------|
| `genAIChromaDBOllamaUrl` | `http://localhost:11434` | Embedding-Server URL |
| `genAIChromaDBOllamaModel` | `bge-m3` | Embedding-Modell |
| `genAIEmbeddingBatchSize` | `5` | Texte pro Request (gegen 413-Fehler) |
| `genAIEmbeddingMaxTextLength` | `8000` | Max. Zeichen pro Text |
| `genAIEmbeddingQueryPrefix` | – | Optionaler Query-Prefix |
| `genAIEmbeddingDocumentPrefix` | – | Optionaler Document-Prefix |

#### RAG-Einstellungen

| Option | Standard | Beschreibung |
|--------|----------|--------------|
| `genAIMaxContextLength` | `8000` | Max. Kontextlänge für LLM |
| `genAITopK` | `10` | Anzahl Suchergebnisse |

#### OpenAI/ChatGPT

| Option | Standard | Beschreibung |
|--------|----------|--------------|
| `genAIOpenAITemperature` | `0.7` | Kreativität (0.0-1.0) |
| `genAIOpenAIMaxTokens` | `4096` | Max. Antwort-Tokens |

#### Authentifizierung (optional)

| Option | Beschreibung |
|--------|--------------|
| `aiServerUser` | Basic Auth Benutzername |
| `aiServerPassword` | Basic Auth Passwort |
| `aiServerHostIP` | Host-Header für Reverse Proxy |

### Beispiel-Konfiguration

```yaml
# config/config.yml
contao:
  localconfig:
    # Ollama Server
    genAIOllamaUrl: 'http://localhost:11434'
    genAIOllamaTimeout: 120
    genAIOllamaKeepAlive: '5m'
    genAIOllamaNumCtx: 4096
    
    # ChromaDB Server
    genAIChromaDBUrl: 'http://localhost:8000'
    genAIChromaDBCollectionName: 'contao-genai'
    
    # Embedding (Ollama)
    genAIChromaDBOllamaUrl: 'http://localhost:11434'
    genAIChromaDBOllamaModel: 'bge-m3'
    
    # RAG
    genAIMaxContextLength: 8000
    genAITopK: 10
    
    # Optional: Authentifizierung
    aiServerUser: 'username'
    aiServerPassword: 'password'
```

---

## Console-Commands

| Command | Beschreibung |
|---------|--------------|
| `genai:index:status` | Status der Indexierung anzeigen |
| `genai:index:sync` | Neue/geänderte Dokumente synchronisieren |
| `genai:index:full` | Vollständiger Reindex aller Dokumente |
| `genai:index:purge` | Index bereinigen (Orphans/Alle) |
| `genai:warmup` | LLM/Embedding-Modelle vorwärmen |

### Beispiele

```bash
# Status anzeigen
php vendor/bin/contao-console genai:index:status

# Vollständiger Reindex
php vendor/bin/contao-console genai:index:full --force

# Index synchronisieren (nur neue)
php vendor/bin/contao-console genai:index:sync --limit=100

# Modelle vorwärmen (Cold-Start vermeiden)
php vendor/bin/contao-console genai:warmup

# Index leeren
php vendor/bin/contao-console genai:index:purge --all
```

---

## Chat-Provider

### Ollama (Lokal)

```php
// tl_genai_chats
'type' => 'ollama',
'url' => 'http://localhost:11434',
'model' => 'qwen3:4b'  // oder eigenes Modelfile
```

**Empfohlene Modelle:**
- `qwen3:4b` – Schnell, gut für Deutsch
- `llama3.2:3b` – Kompakt, effizient
- `mistral:7b` – Ausgewogen
- `qwen3:14b` – Beste Qualität (GPU empfohlen)

### ChatGPT/OpenAI

```php
// tl_genai_chats
'type' => 'chatgpt',
'url' => 'https://api.openai.com',
'model' => 'gpt-4o-mini'
```

**Unterstützte Modelle:**
- `gpt-4o-mini` – Schnell, günstig
- `gpt-4o` – Beste Qualität
- `gpt-4-turbo` – Großes Kontextfenster

---

## Empfohlene Embedding-Modelle

| Modell | Dim. | Größe | Deutsch | Empfehlung |
|--------|------|-------|---------|------------|
| **BGE-M3** | 1024 | 2.2 GB | ⭐⭐⭐⭐⭐ | **Standard** |
| **Qwen3-Embedding** | 1024 | 0.6-8 GB | ⭐⭐⭐⭐⭐ | Beste Qualität |
| Multilingual-E5-Large | 1024 | 2.2 GB | ⭐⭐⭐⭐ | Gut |

```bash
# Standard (empfohlen)
ollama pull bge-m3

# Beste Qualität (GPU)
ollama pull qwen3-embedding:8b
```

---

## Troubleshooting

### 413 Request Entity Too Large

```php
// localconfig.php
$GLOBALS['TL_CONFIG']['genAIEmbeddingBatchSize'] = 1;
$GLOBALS['TL_CONFIG']['genAIEmbeddingMaxTextLength'] = 4000;
```

### Langsame erste Antwort (Cold-Start)

```bash
php vendor/bin/contao-console genai:warmup
```

### Keine Suchergebnisse

1. Index-Status prüfen: `php vendor/bin/contao-console genai:index:status`
2. Bei Modellwechsel: Reindex erforderlich
3. Collection-Filter im Assistenten prüfen

---

## Cronjobs

| Cronjob | Intervall | Beschreibung |
|---------|-----------|--------------|
| `SyncVectors` | Minutely | Pending-Dokumente verarbeiten |
| `CleanupOrphans` | Daily | Verwaiste Index-Einträge entfernen |

```bash
# Manueller Aufruf
php vendor/bin/contao-console contao:cron
```

---

## Lizenz

MIT

## Autor

[Alexander Naumov](https://alexandernaumov.de)

---

💡 **GPU/Server-Konfiguration**: Benötigen Sie Unterstützung bei der Einrichtung von Ollama, ChromaDB oder GPU-Servern? [Kontaktieren Sie mich](https://alexandernaumov.de) für individuelle Beratung und Setup.