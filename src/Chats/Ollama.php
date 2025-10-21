<?php

namespace Alnv\GenaiBundle\Chats;

use Alnv\GenaiBundle\Entities\Chat;
use Alnv\GenaiBundle\Entities\Conversation;
use Alnv\GenaiBundle\Helpers\Toolkit;
use Alnv\GenaiBundle\Vectors\ChromaDb;
use LLPhant\Chat\Enums\ChatRole;
use LLPhant\Chat\Message;
use LLPhant\Chat\OllamaChat;
use LLPhant\OllamaConfig;
use Michelf\MarkdownExtra;

class Ollama // todo add
{

    protected Chat $objChatEntity;

    protected OllamaConfig $objConfig;

    protected array $arrOptions = [];

    protected Conversation $objConversation;

    public function __construct(Chat $objChatEntity, $arrOptions = [])
    {

        $this->objChatEntity = $objChatEntity;
        $this->arrOptions = $arrOptions;

        $arrChatEntity = $this->objChatEntity->getChatArray();

        $this->objConfig = new OllamaConfig();
        $this->objConfig->model = $arrChatEntity['model'] ?? '';
        $this->objConfig->url = $arrChatEntity['url'] ?? '';

        $this->objConversation = new Conversation();
        $this->objConversation->startIfNot($this->arrOptions['assistant']['id'] ?? 0);
    }

    public function ask($strQuestion): array
    {

        $arrMessages = [];

        $this->addInstructionMessage($arrMessages);
        $this->addContext($strQuestion, $arrMessages);

        $objMessage = new Message();
        $objMessage->content = $strQuestion;
        $objMessage->role = ChatRole::User;
        $objMessage->name = Toolkit::getUserName();

        $arrMessages[] = $objMessage;

        $this->objConversation->addMessage([
            'content' => $objMessage->content,
            'role' => $objMessage->role->value,
            'name' => $objMessage->name,
            'createdAt' => \time()
        ]);

        $objOllamaChat = new OllamaChat($this->objConfig);

        $strContent = $objOllamaChat->generateChat($arrMessages);
        $strContent = MarkdownExtra::defaultTransform($strContent);

        $arrAnswer = [
            'content' => $strContent,
            'role' => ChatRole::Assistant->value,
            'name' => $this->arrOptions['assistant']['assistantName'] ?? '',
            'createdAt' => \time()
        ];

        $this->objConversation->addMessage($arrAnswer);

        return $arrAnswer;
    }

    protected function addContext($strQuestion, &$arrMessages): void
    {

        $strContext = $this->getContextRequest($strQuestion);
        $arrContext = $this->getContext($strContext . ' ' . $strQuestion);

        $strDocuments = '';
        $strMeta = '';

        foreach ($arrContext['documents'][0] ?? [] as $strDocument) {
            $strDocuments .= $strDocument;
        }

        foreach ($arrContext['metadatas'][0] ?? [] as $arrMetadatas) {
            $strMeta .= $arrMetadatas['URL'] ?? '';
        }

        $objMessage = new Message();
        $objMessage->content =
            "Kontext:
            $strDocuments
            Quellen-URLs:
            $strMeta
            Anweisung:
            Beantworte die folgende Frage ausschließlich basierend auf dem oben angegebenen Kontext.  Wenn im Kontext keine ausreichenden Informationen zur Beantwortung der Frage vorhanden sind, dann antworte bitte nur mit folgendem Satz (und nichts weiter): „Ich kann Ihre Frage nicht beantworten, da mir keine Informationen darüber bekannt sind.“";

        $objMessage->role = ChatRole::System;

        $arrMessages[] = $objMessage;
    }

    protected function addInstructionMessage(&$arrMessages): void
    {

        if (!($this->arrOptions['assistant']['instructions'] ?? '')) {
            return;
        }

        $objMessage = new Message();
        $objMessage->content = $this->arrOptions['assistant']['instructions'];
        $objMessage->role = ChatRole::System;

        $arrMessages[] = $objMessage;
    }

    protected function getContextRequest($strQuestion)
    {

        $objOllamaChat = new OllamaChat($this->objConfig);

        $arrMessages = [];

        // $this->addInstructionMessage($arrMessages);

        /*
        $arrTopics = [];
        $objEntities = Database::getInstance()->prepare('SELECT * FROM cm_pst WHERE tstamp>? AND visible=?')->execute(0, '1');
        while ($objEntities->next()) {
            $arrTopics[] = $objEntities->name;
        }

        $strTopics = \implode("\n", $arrTopics);
        $strPrompt = "Aufgabe:\n" .
            "1. Ordne die Frage einem der folgenden Themengebiete zu:\n" .
            $strTopics . "\n" .
            "2. Entferne anschließend alle unnötigen Tokens (z.B. Füllwörter, irrelevante Begriffe oder natürliche Sprache), " .
            "um die Frage für eine semantische Suche mit ChromaDB (RAG) zu optimieren. " .
            "Konzentriere dich auf bedeutungstragende Schlüsselwörter.\n" .
            "Frage:\n" . $strQuestion;
        */

        $strPrompt = "
            Du bist ein NLP-Experte. Optimiere die folgende Benutzereingabe für eine semantische Vektor-Datenbanksuche. 
            Ziel ist es, die Anfrage so umzuschreiben oder zu erweitern, dass:
            - Der semantische Gehalt vollständig erhalten bleibt  
            - Die Formulierung möglichst klar, vollständig und präzise ist  
            - Relevante Synonyme oder verwandte Begriffe (wenn sinnvoll) ergänzt werden  
            - Ambiguitäten (Mehrdeutigkeiten) vermieden oder aufgelöst werden  
            - Kontext- und themenbezogene Schlüsselbegriffe explizit gemacht werden
            
            Gib nur die optimierte, suchfreundliche Formulierung zurück – kein Kommentar, keine Erklärungen.
            
            Originalfrage: \"$strQuestion\"
        ";

        $objMessage = new Message();
        $objMessage->name = '';
        $objMessage->content = $strPrompt;
        $objMessage->role = ChatRole::User;

        $arrMessages[] = $objMessage;

        return $objOllamaChat->generateChat($arrMessages);
    }

    protected function getContext($strContext): array
    {

        $objChroma = new ChromaDb();

        return $objChroma->get($strContext); // todo
    }
}