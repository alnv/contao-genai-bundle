<?php

namespace Alnv\GenaiBundle\Chats;

use Alnv\GenaiBundle\Entities\Chat;
use Alnv\GenaiBundle\Entities\Conversation;
use Alnv\GenaiBundle\Helpers\Toolkit;
use Alnv\GenaiBundle\Vectors\ChromaDb;
use Contao\Config;
use LLPhant\Chat\Enums\ChatRole;
use LLPhant\Chat\Message;
use Alnv\GenaiBundle\ChatExtensions\OllamaChat;
use LLPhant\OllamaConfig;
use Michelf\MarkdownExtra;

class Ollama
{
    protected Chat $objChatEntity;
    protected OllamaConfig $objConfig;
    protected array $arrOptions = [];
    protected Conversation $objConversation;

    // Configurable limits
    protected int $maxContextLength = 8000;
    protected int $topK = 10;

    public function __construct(Chat $objChatEntity, array $arrOptions = [])
    {
        $this->objChatEntity = $objChatEntity;
        $this->arrOptions = $arrOptions;
        $arrChatEntity = $this->objChatEntity->getChatArray();
        $this->objConfig = new OllamaConfig();

        // Use configurable timeout, default 120s for streaming
        $this->objConfig->timeout = (int) (Config::get('genAIOllamaTimeout') ?: 120);
        $this->objConfig->model = $arrChatEntity['model'] ?? '';
        $this->objConfig->url = $arrChatEntity['url'] ?? '';

        // Configurable context limits
        $this->maxContextLength = (int) (Config::get('genAIMaxContextLength') ?: 8000);
        $this->topK = (int) (Config::get('genAITopK') ?: 10);

        $this->objConversation = new Conversation();
        $this->objConversation->startIfNot($this->arrOptions['assistant']['id'] ?? 0);
    }

    public function getConversation(): Conversation
    {
        return $this->objConversation;
    }

    public function getConfig(string $question, &$messages, &$sources): OllamaChat
    {
        // 1. Get context FIRST (needed for system message)
        $arrContext = $this->getContext($question);

        // 2. Extract sources for frontend display
        $sources = $this->extractSources($arrContext);

        // 3. Build context block
        $strContextBlock = $this->buildContextBlock($arrContext);

        // 4. System message with STATIC instructions + CONTEXT
        // This order enables prompt caching: static part first, then dynamic context
        $this->addSystemMessage($messages, $strContextBlock);

        // 5. User question (simple, just the question)
        $objMessage = new Message();
        $objMessage->role = ChatRole::User;
        $objMessage->name = Toolkit::getUserName();
        $objMessage->content = $question;

        $messages[] = $objMessage;

        // Save user message to conversation
        $this->objConversation->addMessage([
            'content' => $question,
            'role' => ChatRole::User->value,
            'name' => $objMessage->name,
            'createdAt' => \time()
        ]);

        // Create chat with Ollama-specific options
        $ollamaChat = new OllamaChat($this->objConfig);

        // Set Ollama-specific options for performance
        $ollamaChat->setModelOptions([
            'keep_alive' => Config::get('genAIOllamaKeepAlive') ?: '5m',
            'num_ctx' => (int) (Config::get('genAIOllamaNumCtx') ?: 4096),
        ]);

        return $ollamaChat;
    }

    public function ask(string $question): array
    {
        $sources = [];
        $messages = [];

        $ollamaChat = $this->getConfig($question, $messages, $sources);
        $content = $ollamaChat->generateChat($messages);
        $content = MarkdownExtra::defaultTransform($content);

        $answer = [
            'content' => $content,
            'createdAt' => \time(),
            'role' => ChatRole::Assistant->value,
            'sources' => \array_values($sources),
            'name' => $this->arrOptions['assistant']['assistantName'] ?? ''
        ];

        $this->objConversation->addMessage($answer);

        return $answer;
    }

    /**
     * Build system message with static instructions first, then context
     * This order enables LLM prompt caching (static prefix can be cached)
     */
    protected function addSystemMessage(array &$messages, string $contextBlock): void
    {
        $instructions = $this->arrOptions['assistant']['instructions'] ?? '';

        // Static instructions first (cacheable)
        $systemContent = !empty($instructions)
            ? $instructions . "\n\n"
            : "";

        // Context instructions (static template)
        $systemContent .= "Nutze ausschließlich den folgenden Kontext, um Fragen zu beantworten. " .
            "Wenn keine relevanten Informationen im Kontext vorhanden sind, sage dies ehrlich. " .
            "Gib bei Antworten die Quellen-URLs an, wenn möglich.\n\n";

        // Dynamic context (changes per request, but at the end)
        $systemContent .= "### KONTEXT ###\n" . $contextBlock . "\n### ENDE KONTEXT ###";

        $objMessage = new Message();
        $objMessage->content = $systemContent;
        $objMessage->role = ChatRole::System;

        $messages[] = $objMessage;
    }

    /**
     * Extract sources from ChromaDB results for frontend display
     * Supports both old and new metadata formats
     */
    protected function extractSources(array $arrContext): array
    {
        $sources = [];

        foreach ($arrContext['metadatas'][0] ?? [] as $meta) {
            // Support both old (uppercase) and new (lowercase) metadata keys
            $url = $meta['url'] ?? $meta['URL'] ?? '';
            $title = $meta['title'] ?? $meta['TITLE'] ?? '';

            if ($url && !isset($sources[$url])) {
                $sources[$url] = [
                    'name' => $title ?: $url,
                    'url' => $url
                ];
            }
        }

        return $sources;
    }

    protected function buildContextBlock(array $arrContext): string
    {
        $strContext = "";
        $documents = $arrContext['documents'][0] ?? [];
        $metadata = $arrContext['metadatas'][0] ?? [];

        $totalLength = 0;

        foreach ($documents as $index => $document) {
            // Support both old (uppercase) and new (lowercase) metadata keys
            $url = $metadata[$index]['url'] ?? $metadata[$index]['URL'] ?? '';
            $title = $metadata[$index]['title'] ?? $metadata[$index]['TITLE'] ?? '';

            $block = \sprintf(
                "---\nQuelle %d%s:\n%s\nURL: %s\n",
                ($index + 1),
                $title ? " ({$title})" : '',
                \trim($document),
                $url ?: 'N/A'
            );

            if (($totalLength + \strlen($block)) > $this->maxContextLength) {
                break;
            }

            $strContext .= $block;
            $totalLength += \strlen($block);
        }

        return $strContext ?: "Kein passender Kontext in der Datenbank gefunden.";
    }

    protected function getContext(string $strContext): array
    {
        // Build filters from assistant/chat configuration
        $filters = [];

        // Root page filter for multi-site setups (includes language via root page)
        $rootPage = $this->arrOptions['rootPage'] ?? null;
        if (!$rootPage && !empty($this->arrOptions['assistant']['rootPage'])) {
            $rootPage = $this->arrOptions['assistant']['rootPage'];
        }
        if ($rootPage) {
            $filters['root_page'] = (string) $rootPage;
        }

        // Collection filter - only use documents from selected collections
        $collectionIds = $this->arrOptions['assistant']['documentCollections'] ?? null;
        if ($collectionIds) {
            // Deserialize if stored as serialized array
            if (is_string($collectionIds)) {
                $collectionIds = \Contao\StringUtil::deserialize($collectionIds, true);
            }
            if (!empty($collectionIds)) {
                $filters['collection_ids'] = $collectionIds;
            }
        }

        // Get embedding configuration from selected collections
        $embeddingConfig = $this->getEmbeddingConfigFromCollections($collectionIds);

        $objChroma = new ChromaDb();
        $objChroma->setOllamaEmbedding(
            $embeddingConfig['url'],
            $embeddingConfig['model']
        );

        return $objChroma->get($strContext, $this->topK, !empty($filters) ? $filters : null);
    }

    /**
     * Get embedding configuration from global settings
     * 
     * The $collectionIds parameter is retained for API compatibility but ignored.
     * All embedding configuration is now centralized in global config.
     */
    protected function getEmbeddingConfigFromCollections(?array $collectionIds): array
    {
        return [
            'url' => Config::get('genAIChromaDBOllamaUrl'),
            'model' => Config::get('genAIChromaDBOllamaModel')
        ];
    }
}
