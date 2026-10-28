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

    public function __construct(Chat $objChatEntity, array $arrOptions = [])
    {
        $this->objChatEntity = $objChatEntity;
        $this->arrOptions = $arrOptions;
        $arrChatEntity = $this->objChatEntity->getChatArray();
        $this->objConfig = new OllamaConfig();

        $this->objConfig->timeout = 90;
        $this->objConfig->model = $arrChatEntity['model'] ?? '';
        $this->objConfig->url = $arrChatEntity['url'] ?? '';

        $this->objConversation = new Conversation();
        $this->objConversation->startIfNot($this->arrOptions['assistant']['id'] ?? 0);
    }

    public function getConversation(): Conversation
    {
        return $this->objConversation;
    }

    public function getConfig(string $question, &$messages, &$sources): OllamaChat
    {
        $this->addInstructionMessage($messages);
        $arrContext = $this->getContext($question);

        foreach ($arrContext['metadatas'] ?? [] as $metas) {
            foreach ($metas as $meta) {
                $url = $meta['URL'] ?? '';
                $sources[$url] = [
                    'name' => $meta['TITLE'] ?? '',
                    'url' => $meta['URL'] ?? ''
                ];
            }
        }

        $strContextBlock = $this->buildContextBlock($arrContext);

        $objMessage = new Message();
        $objMessage->role = ChatRole::User;
        $objMessage->name = Toolkit::getUserName();
        $objMessage->content = $this->buildFinalUserContent($strContextBlock, $question);

        $messages[] = $objMessage;

        $this->objConversation->addMessage([
            'content' => $question,
            'role' => ChatRole::User->value,
            'name' => $objMessage->name,
            'createdAt' => \time()
        ]);

        return new OllamaChat($this->objConfig);
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

    protected function addInstructionMessage(array &$messages): void
    {
        if (empty($this->arrOptions['assistant']['instructions'] ?? '')) {
            return;
        }

        $objMessage = new Message();
        $objMessage->content = $this->arrOptions['assistant']['instructions'];
        $objMessage->role = ChatRole::System;

        $messages[] = $objMessage;
    }

    protected function buildContextBlock(array $arrContext): string
    {
        $strContext = "";
        $documents = $arrContext['documents'][0] ?? [];
        $metadata = $arrContext['metadatas'][0] ?? [];

        $totalLength = 0;
        $maxLength = 4000;

        foreach ($documents as $index => $document) {
            $url = $metadata[$index]['URL'] ?? 'Keine URL vorhanden';

            $block = \sprintf(
                "--- \nQuelle %d:\nText: %s\nLink/URL: %s\n",
                ($index + 1),
                \trim($document),
                $url
            );

            if (($totalLength + \strlen($block)) > $maxLength) {
                break;
            }

            $strContext .= $block;
            $totalLength .= \strlen($block);
        }

        return $strContext ?: "Kein passender Kontext in der Datenbank gefunden.";
    }

    protected function buildFinalUserContent(string $strContext, string $question): string
    {
        return "Nutze ausschließlich den folgenden Kontext, um die Frage am Ende zu beantworten.\n\n" .
            "### START KONTEXT ###\n" .
            $strContext . "\n" .
            "### ENDE KONTEXT ###\n\n" .
            "Frage: \"" . $question . "\"\n\n";
    }

    protected function getContextRequest(string $question): string
    {
        $config = new OllamaConfig();
        $config->url = $this->objConfig->url;
        $config->model = 'chromadb-search-bot:latest';
        $config->timeout = 90;

        $objOllamaChat = new OllamaChat($config);

        $objMessage = new Message();
        $objMessage->role = ChatRole::User;
        $objMessage->content = $question;

        return \trim($objOllamaChat->generateChat([$objMessage]));
    }

    protected function getContext(string $strContext): array
    {
        $objChroma = new ChromaDb();
        $objChroma->setOllamaEmbedding(
            Config::get('genAIChromaDBOllamaUrl'),
            Config::get('genAIChromaDBOllamaModel')
        );

        return $objChroma->get($strContext);
    }
}