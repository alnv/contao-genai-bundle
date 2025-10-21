<?php

namespace Alnv\GenaiBundle\Assistants;

// use LLPhant\Chat\Enums\ChatRole;
// use LLPhant\Chat\OllamaChat;
// use LLPhant\OllamaConfig;
// use LLPhant\Chat\Message;
// use Alnv\GenaiBundle\Vectors\ChromaDb;

class Ollama
{

    /*
    protected string $strPrompt = "";

    public function test()
    {

        $strQuestion = 'kfz abmelden';

        $objChroma = new ChromaDb();
        $arrDocuments = $objChroma->get($strQuestion);
        // dd($arrDocuments['documents'][0][0] ?? '');

        $objConfig = new OllamaConfig();
        $objConfig->model = 'gemma3:latest';
        $objConfig->url = 'http://167.235.148.87:11434/api/';

        $objOllamaChat = new OllamaChat($objConfig);

        $objMessage = new Message();
        $objMessage->content = $this->strPrompt;
        $objMessage->role = ChatRole::System;

        $messages[] = $objMessage;

        $objMessage = new Message();
        $objMessage->name = 'Alex';
        $objMessage->content = 'Das ist der Kontext: ' . ($arrDocuments['documents'][0][0] ?? '') . ' - URL:' . ($arrDocuments['metadatas'][0][0]['URL'] ?? '') . ' - Das ist die Frage: ' . $strQuestion;
        $objMessage->role = ChatRole::User;

        $messages[] = $objMessage;

        $response = $objOllamaChat->generateChat($messages);

        \file_put_contents('/Users/alexandernaumov/Sites/potsdam-mittelmark_dev/chat.txt', $response);
    }
    */
}