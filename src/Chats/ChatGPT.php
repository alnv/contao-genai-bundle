<?php

namespace Alnv\GenaiBundle\Chats;

use Alnv\GenaiBundle\ChatExtensions\OpenAIChat;
use Alnv\GenaiBundle\Entities\Chat;
use Alnv\GenaiBundle\Entities\Conversation;
use Alnv\GenaiBundle\Helpers\Toolkit;
use Alnv\GenaiBundle\Vectors\ChromaDb;
use Contao\Config;
use LLPhant\Chat\Enums\ChatRole;
use LLPhant\Chat\Message;
use LLPhant\OpenAIConfig;
use Michelf\MarkdownExtra;

/**
 * ChatGPT / OpenAI Chat Integration
 * 
 * Handles chat requests with OpenAI models including:
 * - SSE streaming support
 * - RAG context retrieval from ChromaDB
 * - Collection filtering
 * - Conversation management
 */
class ChatGPT
{
    protected Chat $objChatEntity;
    protected OpenAIConfig $objConfig;
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

        $this->objConfig = new OpenAIConfig();
        $this->objConfig->model = $arrChatEntity['model'] ?? 'gpt-4o-mini';
        
        // API key: use provided token or dummy for local APIs (LLPhant requires non-empty)
        $this->objConfig->apiKey = !empty($arrChatEntity['token']) 
            ? $arrChatEntity['token'] 
            : 'local-api-no-key-required';

        // URL: use provided URL or default to OpenAI
        $url = !empty($arrChatEntity['url']) 
            ? $arrChatEntity['url'] 
            : 'https://api.openai.com/v1/';
        // Ensure trailing slash for Guzzle path resolution
        if (!str_ends_with($url, '/')) {
            $url .= '/';
        }
        $this->objConfig->url = $url;

        // Use configurable timeout
        $this->objConfig->timeout = (int) (Config::get('genAIOllamaTimeout') ?: 120);

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

    /**
     * Prepare chat configuration for streaming
     * 
     * @param string $question User's question
     * @param array &$messages Output: prepared messages array
     * @param array &$sources Output: extracted sources for frontend
     * @return OpenAIChat Configured chat object ready for streaming
     */
    public function getConfig(string $question, array &$messages, array &$sources): OpenAIChat
    {
        $arrContext = $this->getContext($question);
        $sources = $this->extractSources($arrContext);
        $strContextBlock = $this->buildContextBlock($arrContext);

        $this->addSystemMessage($messages, $strContextBlock);

        $objMessage = new Message();
        $objMessage->role = ChatRole::User;
        $objMessage->name = Toolkit::getUserName();
        $objMessage->content = $question;

        $messages[] = $objMessage;

        $this->objConversation->addMessage([
            'content' => $question,
            'role' => ChatRole::User->value,
            'name' => $objMessage->name,
            'createdAt' => \time()
        ]);

        $openAIChat = new OpenAIChat($this->objConfig);

        $openAIChat->setModelOptions([
            'temperature' => (float) (Config::get('genAIOpenAITemperature') ?: 0.7),
            'max_tokens' => (int) (Config::get('genAIOpenAIMaxTokens') ?: 4096),
        ]);

        return $openAIChat;
    }

    /**
     * Non-streaming ask method (legacy support)
     */
    public function ask(string $question): array
    {
        $sources = [];
        $messages = [];

        $openAIChat = $this->getConfig($question, $messages, $sources);
        $content = $openAIChat->generateChat($messages);
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

    /**
     * Build formatted context block from ChromaDB results
     */
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

    /**
     * Get context from ChromaDB with filtering
     */
    protected function getContext(string $strContext): array
    {
        // Build filters from assistant/chat configuration
        $filters = [];

        // Root page filter for multi-site setups
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

        // Get embedding configuration from global config
        $embeddingConfig = $this->getEmbeddingConfig();

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
     * Uses the same Ollama embeddings as the Ollama chat implementation.
     * OpenAI embeddings can be added later if needed.
     */
    protected function getEmbeddingConfig(): array
    {
        return [
            'url' => Config::get('genAIChromaDBOllamaUrl'),
            'model' => Config::get('genAIChromaDBOllamaModel')
        ];
    }
}
