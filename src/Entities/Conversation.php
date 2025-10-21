<?php

namespace Alnv\GenaiBundle\Entities;

use Alnv\GenaiBundle\Helpers\Getters;
use Contao\Database;
use Contao\FrontendUser;
use Contao\StringUtil;
use Contao\System;

class Conversation
{

    private $objSession;

    protected array $arrConversation = [];

    public function __construct()
    {
        $this->objSession = System::getContainer()->get('request_stack')->getSession();
    }

    public function addMessage($arrMessage): void
    {

        $arrConversation = $this->getConversion();
        $objCurrentConversation = Database::getInstance()->prepare('SELECT * FROM tl_genai_conversations WHERE id=?')->limit(1)->execute($arrConversation['id'] ?? 0);

        $arrMessages =StringUtil::deserialize($objCurrentConversation?->messages, true);
        $arrMessages[] = $arrMessage;

        Database::getInstance()
            ->prepare('UPDATE tl_genai_conversations %s WHERE id=?')
            ->set([
                'tstamp' => \time(),
                'messages' => \serialize($arrMessages)
            ])
            ->execute($arrConversation['id'] ?? 0);
    }

    public function reset(): void
    {
        $this->objSession->set('gen-ai-conversation-session-id', '');
    }

    public function getMessages(): array
    {

        $arrConversation = $this->getConversion();

        return $arrConversation['messages'] ?? [];
    }

    public function start($strAssistantId): array
    {

        $arrSet = [
            'tstamp' => \time(),
            'created_at' => \time(),
            'member_id' => FrontendUser::getInstance()?->id,
            'session' => $this->getSessionId(),
            'assistant_id' => $strAssistantId,
            'messages' => \serialize([])
        ];

        $objInsert = Database::getInstance()->prepare('INSERT INTO tl_genai_conversations %s')->set($arrSet)->execute();

        $arrSet['id'] = $objInsert->insertId;

        $this->arrConversation = $arrSet;

        return $arrSet;
    }

    public function startIfNot($strAssistantId): array
    {

        $strSessionId = $this->getSessionId();
        $arrConversion = Getters::getConversationBySessionId($strSessionId);

        if (empty($arrConversion)) {
            return $this->start($strAssistantId);
        }

        $this->arrConversation = $arrConversion;

        return $arrConversion;
    }

    protected function getConversion(): array
    {

        $this->arrConversation['messages'] = StringUtil::deserialize($this->arrConversation['messages'] ?? [], true);

        return $this->arrConversation;
    }

    protected function getSessionId(): string
    {

        $strSessionId = $this->objSession->get('gen-ai-conversation-session-id');

        if (!$strSessionId) {
            $strSessionId = substr(\md5(\uniqid() . '.' . \time()), 0, 128);
            $this->objSession->set('gen-ai-conversation-session-id', $strSessionId);
        }

        return $strSessionId;
    }
}