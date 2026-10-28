<?php

namespace Alnv\GenaiBundle\Entities;

use Alnv\GenaiBundle\Helpers\Getters;

class Assistant
{

    protected array $arrAssistant;
    protected Chat $objChat;

    public function __construct($intAssistantId)
    {
        $this->arrAssistant = Getters::getAssistant($intAssistantId);

        $this->setChat();
    }

    public function getAssistantId()
    {
        return $this->arrAssistant['id'] ?? 0;
    }

    public function getAssistantArray(): array
    {
        return $this->arrAssistant ?? [];
    }

    public function setChat(): void
    {
        $this->objChat = new Chat($this->arrAssistant['chat'] ?? 0, [
            'assistant' => $this->arrAssistant
        ]);
    }

    public function messages(): array
    {
        $objConversation = new Conversation();
        $objConversation->startIfNot($this->arrAssistant['id'] ?? 0);

        return $objConversation->getMessages();
    }

    public function resetConversion(): void
    {
        $objConversation = new Conversation();

        $objConversation->reset();
    }

    public function ask($strQuestion): array
    {
        $objChatObject = $this->objChat->getChatObject();
        if (!$objChatObject) {
            return [];
        }

        return $objChatObject->ask($strQuestion);
    }

    public function getChat()
    {
        $objChatObject = $this->objChat->getChatObject();
        if (!$objChatObject) {
            return [];
        }

        return $objChatObject;
    }
}