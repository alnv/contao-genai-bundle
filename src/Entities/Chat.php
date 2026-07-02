<?php

namespace Alnv\GenaiBundle\Entities;

use Alnv\GenaiBundle\Chats\Ollama;
use Alnv\GenaiBundle\Chats\ChatGPT;
use Alnv\GenaiBundle\Helpers\Getters;

class Chat
{

    protected array $arrChat;

    protected array $arrOptions;

    public function __construct($intChatId, $arrOptions = [])
    {
        $this->arrChat = Getters::getChat($intChatId);
        $this->arrOptions = $arrOptions;
    }

    public function getChatArray(): array
    {
        return $this->arrChat ?? [];
    }

    public function getChatObject()
    {
        switch ($this->arrChat['type']) {
            case 'ollama':
                return new Ollama($this, $this->arrOptions);
            case 'chatgpt':
                return new ChatGPT($this, $this->arrOptions);
        }

        return null;
    }
}