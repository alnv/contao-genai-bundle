# GenAI Bundle für Contao

RAG-Chatbot für Contao CMS mit lokalen LLMs (Ollama), OpenAI/ChatGPT und ChromaDB Vektorsuche.

## Features

- 🤖 **Lokale LLMs** via Ollama (keine Cloud-Abhängigkeit, Datenschutz-konform)
- 🌐 **OpenAI/ChatGPT** Unterstützung mit Streaming
- 🔌 **OpenAI-kompatible APIs** — Lokale Server wie llama.cpp, LM Studio, vLLM
- 🔍 **RAG (Retrieval Augmented Generation)** mit ChromaDB
- ⚡ **Streaming-Antworten** via Server-Sent Events (SSE)
- 🌐 **Multisite-fähig** mit Root-Page-Filterung
- 📄 **Automatische Seitenindexierung** via PageCrawler
- 🎨 **Vue 3 Chat-Widget** für das Frontend
- 🔒 **Sicherheit**: CSRF-Schutz, XSS-Prävention via DOMPurify

## Voraussetzungen

- Contao 5.3+
- PHP 8.2+
- [Ollama](https://ollama.ai/) für LLM und Embeddings (oder OpenAI-kompatible API)
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

## Chat-Provider

### Ollama (Lokal)

Der Standard für lokale LLMs ohne Cloud-Abhängigkeit.

```php
// tl_genai_chats
'type' => 'ollama',
'url' => 'http://localhost:11434',
'model' => 'qwen3:4b'
```

**Empfohlene Modelle:**
| Modell | RAM | Beschreibung |
|--------|-----|--------------|
| `qwen3:4b` | ~3 GB | Schnell, gut für Deutsch |
| `llama3.2:3b` | ~2 GB | Kompakt, effizient |
| `mistral:7b` | ~5 GB | Ausgewogen |
| `qwen3:14b` | ~10 GB | Beste Qualität (GPU empfohlen) |

### ChatGPT/OpenAI

Für OpenAI's Cloud-API.

```php
// tl_genai_chats
'type' => 'chatgpt',
'url' => 'https://api.openai.com/v1/',
'token' => 'sk-...',
'model' => 'gpt-4o-mini'
```

**Unterstützte Modelle:**
- `gpt-4o-mini` – Schnell, günstig
- `gpt-4o` – Beste Qualität
- `gpt-4-turbo` – Großes Kontextfenster

### Lokale KI mit OpenAI-kompatibler API

Der **ChatGPT-Typ** kann als Proxy für lokale LLM-Server verwendet werden, die eine OpenAI-kompatible API bereitstellen. Das ermöglicht die Nutzung von lokalen Modellen ohne den Ollama-Overhead.

**Unterstützte Server:**

| Server | Standard-URL | Beschreibung |
|--------|--------------|--------------|
| **[Strata](https://github.com/Niko1221/Strata)** | `http://127.0.0.1:8080/v1/` | ⭐ **Empfohlen** — One-Click Install, optimiert |
| **llama.cpp** | `http://127.0.0.1:8080/v1/` | Minimaler, schneller C++ Server |
| **LM Studio** | `http://localhost:1234/v1/` | Desktop-App mit GUI |
| **Ollama** (OpenAI-Modus) | `http://localhost:11434/v1/` | Ollama via OpenAI API |
| **vLLM** | `http://localhost:8000/v1/` | Hochperformant für GPUs |

> 💡 **Empfehlung:** [Strata](https://github.com/Niko1221/Strata) bietet One-Click Installation für Windows/Linux, optimierte Quantisierung (ISTA-DASLab) und läuft auf Consumer-Hardware. Ideal für Qwen3.8-Flash-Next.

**Konfiguration im Backend:**

| Feld | Wert |
|------|------|
| Typ | `chatgpt` |
| URL | `http://127.0.0.1:8080/v1/` |
| Token | *(leer lassen)* |
| Modell | `qwen3.8-flash-next` (je nach Server) |

> **Hinweis:** Die meisten lokalen Server benötigen kein API-Token. Das Token-Feld kann leer bleiben.

**Beispiel: Strata starten**

```bash
# Strata mit Qwen3.8-Flash-Next (empfohlen)
strata launch --family qwen3.8-flash-next --model iq3_s

# Oder manuell mit llama.cpp
./llama-server -m qwen3-8b-q4_k_m.gguf --port 8080
```

---

## Hardware-Empfehlungen

### Übersicht nach GPU

| GPU | VRAM | Empfohlene Modelle | Quantisierung |
|-----|------|-------------------|---------------|
| **RTX 5090** | 32 GB | Qwen3:32b, DeepSeek-V4.1 (partial) | Q4_K_M, Q5_K_M |
| **RTX 5080** | 16 GB | Qwen3:14b, Llama3.3:70b (partial) | Q4_K_M |
| **RTX 4090** | 24 GB | Qwen3:14b, Mistral-Large | Q5_K_M, Q6_K |
| **RTX 4080** | 16 GB | Qwen3:8b, Llama3.2:8b | Q4_K_M, Q5_K_M |
| **RTX 4070** | 12 GB | Qwen3:4b, Mistral:7b | Q4_K_M |
| **RTX 3090** | 24 GB | Qwen3:14b, Codestral | Q4_K_M, Q5_K_M |
| **RTX 3080** | 10 GB | Qwen3:4b, Llama3.2:3b | Q4_K_S, IQ4_XS |
| **RTX 3060** | 12 GB | Qwen3:4b, Phi-3 | Q4_K_M |
| **Apple M4 Max** | 128 GB | DeepSeek-V4.1, Qwen3:32b | Q4_K_M |
| **Apple M3 Pro** | 36 GB | Qwen3:14b, Codestral | Q4_K_M |
| **Apple M2** | 16 GB | Qwen3:4b, Llama3.2:8b | Q4_K_S |

### CPU-Only (ohne GPU)

| RAM | Empfohlene Modelle | Hinweis |
|-----|-------------------|---------|
| 64 GB | Qwen3:8b, Mistral:7b | Langsam, aber nutzbar |
| 32 GB | Qwen3:4b, Phi-3:mini | Akzeptabel für einfache Aufgaben |
| 16 GB | Qwen3:0.6b, TinyLlama | Nur für Tests |

### Quantisierung erklärt

| Quantisierung | Qualität | Speicher | Empfehlung |
|---------------|----------|----------|------------|
| **Q8_0** | ⭐⭐⭐⭐⭐ | Hoch | Wenn VRAM reicht |
| **Q6_K** | ⭐⭐⭐⭐⭐ | Mittel-Hoch | Guter Kompromiss |
| **Q5_K_M** | ⭐⭐⭐⭐ | Mittel | Standard-Empfehlung |
| **Q4_K_M** | ⭐⭐⭐⭐ | Niedrig | Beste Balance |
| **IQ4_XS** | ⭐⭐⭐ | Sehr niedrig | Speicher knapp |
| **IQ3_S** | ⭐⭐⭐ | Minimal | Consumer-GPUs |
| **Q2_K** | ⭐⭐ | Minimal | Nur wenn nötig |

### Strata Hardware-Matrix

[Strata](https://github.com/Niko1221/Strata) optimiert automatisch für Ihre Hardware:

| Hardware | Qwen3.8-Flash-Next | DeepSeek-V4.1 |
|----------|-------------------|---------------|
| RTX 5070 (12 GB) + 64 GB RAM | ✅ IQ3_S, 4-6 tok/s | ✅ 2.00bpw (SSD-Offload) |
| RTX 4090 (24 GB) | ✅ Q4_K_M, 15+ tok/s | ⚠️ Partial, langsam |
| RTX 3060 (12 GB) | ✅ IQ2_XS/IQ3_XXS | ❌ Zu groß |
| Apple M4 Max (128 GB) | ✅ Q5_K_M, 20+ tok/s | ✅ 2.25bpw |
| Apple M3 Pro (36 GB) | ✅ Q4_K_M | ❌ Zu wenig RAM |

> **Tipp:** Für DeepSeek-V4.1 auf Consumer-Hardware benötigt Strata ~250 GB freien SSD-Speicher für Offloading

---

## Ollama Modelfile

Mit einem **Modelfile** können Sie ein bestehendes Ollama-Modell anpassen und optimieren.

### Vorteile

| Vorteil | Beschreibung |
|---------|--------------|
| **Konsistentes Verhalten** | System-Prompt ist fest im Modell verankert |
| **Schnellerer Start** | Kein System-Prompt bei jeder Anfrage nötig |
| **Angepasste Parameter** | Temperatur, Kontext-Größe, etc. vordefiniert |
| **Einfache Verteilung** | Modell mit `ollama push` teilen |

### Beispiel: Deutschsprachiger Support-Assistent

```dockerfile
# Basis-Modell
FROM qwen3:4b

# System-Prompt
SYSTEM """
Du bist ein freundlicher Kundenservice-Assistent für ein deutsches Unternehmen.

Deine Aufgaben:
- Beantworte Fragen basierend auf den bereitgestellten Dokumenten
- Antworte immer auf Deutsch
- Sei höflich, präzise und hilfreich
- Wenn du etwas nicht weißt, sage es ehrlich

Formatierung:
- Nutze Markdown für strukturierte Antworten
- Verwende Listen für mehrere Punkte
"""

# Parameter
PARAMETER temperature 0.7
PARAMETER num_ctx 8192
PARAMETER top_p 0.9
```

```bash
# Modell erstellen
ollama create mein-assistent -f Modelfile

# Testen
ollama run mein-assistent "Hallo, wer bist du?"
```

### Nützliche Parameter

| Parameter | Standard | Beschreibung |
|-----------|----------|--------------|
| `temperature` | 0.8 | Kreativität (0.0 = deterministisch, 1.0 = kreativ) |
| `num_ctx` | 2048 | Kontextfenster-Größe in Tokens |
| `top_p` | 0.9 | Nucleus Sampling |
| `repeat_penalty` | 1.1 | Vermeidung von Wiederholungen |

---

## Schnellstart: Backend-Konfiguration

### 1. Chat erstellen

**GenAI → Chats → Neu**

| Feld | Beispielwert |
|------|--------------|
| Name | `Ollama Qwen3` |
| Typ | `ollama` |
| URL | `http://localhost:11434` |
| Modell | `qwen3:4b` |

### 2. Dokument-Collection erstellen

**GenAI → Dokumente → Neu**

| Feld | Beispielwert |
|------|--------------|
| Titel | `Website-Inhalte` |
| Root-Page | `Meine Website [de]` |

Die Seiten werden automatisch via PageCrawler indexiert.

### 3. Assistenten erstellen

**GenAI → Assistenten → Neu**

| Feld | Beispielwert |
|------|--------------|
| Name | `Support-Assistent` |
| Assistentenname | `KI-Assistent` |
| Begrüßungstext | `Hallo! Wie kann ich Ihnen helfen?` |
| Instruktionen | `Du bist ein hilfreicher Assistent. Antworte auf Deutsch.` |
| Dokument-Collections | ☑️ `Website-Inhalte` |
| Chat | `Ollama Qwen3` |

### 4. Inhaltselement anlegen

**Artikel → Inhaltselement → GenAI Chat**

| Feld | Beispielwert |
|------|--------------|
| Überschrift | `Fragen Sie unseren Assistenten` |
| Assistent | `Support-Assistent` |

### 5. Indexierung starten

```bash
# Vollständiger Index
php vendor/bin/contao-console genai:index:full

# Status prüfen
php vendor/bin/contao-console genai:index:status
```

---

## Konfiguration

### Alle Optionen

Die Konfiguration erfolgt in `config/config.yml`:

```yaml
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
    genAIEmbeddingBatchSize: 5
    genAIEmbeddingMaxTextLength: 8000
    
    # RAG
    genAIMaxContextLength: 8000
    genAITopK: 10
    
    # Optional: Authentifizierung
    aiServerUser: 'username'
    aiServerPassword: 'password'
```

### Optionen-Referenz

#### Ollama

| Option | Standard | Beschreibung |
|--------|----------|--------------|
| `genAIOllamaUrl` | `http://localhost:11434` | Ollama API URL |
| `genAIOllamaTimeout` | `120` | Timeout in Sekunden |
| `genAIOllamaKeepAlive` | `5m` | Modell im RAM halten |
| `genAIOllamaNumCtx` | `4096` | Kontextfenster-Größe |

#### ChromaDB

| Option | Standard | Beschreibung |
|--------|----------|--------------|
| `genAIChromaDBUrl` | `http://localhost:8000` | ChromaDB API URL |
| `genAIChromaDBCollectionName` | `genai` | Basis-Collection-Name |
| `genAIChromaDBTenant` | `default_tenant` | ChromaDB Tenant |
| `genAIChromaDBDatabase` | `default_database` | ChromaDB Database |

#### Embedding

| Option | Standard | Beschreibung |
|--------|----------|--------------|
| `genAIChromaDBOllamaModel` | `bge-m3` | Embedding-Modell |
| `genAIEmbeddingBatchSize` | `5` | Texte pro Request |
| `genAIEmbeddingMaxTextLength` | `8000` | Max. Zeichen pro Text |

---

## Console-Commands

| Command | Beschreibung |
|---------|--------------|
| `genai:index:status` | Status der Indexierung anzeigen |
| `genai:index:sync` | Neue/geänderte Dokumente synchronisieren |
| `genai:index:full` | Vollständiger Reindex aller Seiten |
| `genai:index:purge` | Index bereinigen |
| `genai:warmup` | LLM/Embedding-Modelle vorwärmen |

```bash
# Status anzeigen
php vendor/bin/contao-console genai:index:status

# Vollständiger Reindex
php vendor/bin/contao-console genai:index:full --force

# Modelle vorwärmen (Cold-Start vermeiden)
php vendor/bin/contao-console genai:warmup
```

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

# Beste Qualität
ollama pull qwen3-embedding:8b
```

---

## Troubleshooting

### 413 Request Entity Too Large

```php
$GLOBALS['TL_CONFIG']['genAIEmbeddingBatchSize'] = 1;
$GLOBALS['TL_CONFIG']['genAIEmbeddingMaxTextLength'] = 4000;
```

### Langsame erste Antwort (Cold-Start)

```bash
php vendor/bin/contao-console genai:warmup
```

### Keine Suchergebnisse

1. Index-Status prüfen: `genai:index:status`
2. Bei Modellwechsel: Reindex erforderlich
3. Collection-Filter im Assistenten prüfen

### Bad Request bei lokaler KI

1. URL muss mit `/` enden: `http://127.0.0.1:8080/v1/`
2. Modellname muss zum Server passen
3. Server-Logs prüfen

---

## Cronjobs

| Cronjob | Intervall | Beschreibung |
|---------|-----------|--------------|
| `SyncVectors` | Minutely | Pending-Dokumente verarbeiten |
| `CleanupOrphans` | Daily | Verwaiste Index-Einträge entfernen |

---

## Lizenz

MIT

## Autor

[Alexander Naumov](https://alexandernaumov.de)

---

💡 **GPU/Server-Konfiguration**: Benötigen Sie Unterstützung bei der Einrichtung von Ollama, ChromaDB oder GPU-Servern? [Kontaktieren Sie mich](https://alexandernaumov.de) für individuelle Beratung und Setup.
