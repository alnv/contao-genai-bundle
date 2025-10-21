<?php

namespace Alnv\GenaiBundle\Helpers;

use Contao\Database;

class Getters
{

    public static function getAllChats(): array
    {

        $arrChats = [];
        $objEntities = Database::getInstance()->prepare('SELECT * FROM tl_genai_chats WHERE tstamp > 0 ORDER BY name')->execute();

        while ($objEntities->next()) {
            $arrChats[] = $objEntities->row();
        }

        return $arrChats;
    }

    public static function getConversationBySessionId($strSessionId): array
    {
        return Database::getInstance()->prepare('SELECT * FROM tl_genai_conversations WHERE `session`=?')->limit(1)->execute($strSessionId)->row();
    }

    public static function getAssistant($intAssistantId): array
    {
        return Database::getInstance()->prepare('SELECT * FROM tl_genai_assistants WHERE id=?')->limit(1)->execute($intAssistantId)->row();
    }

    public static function getChat($intChatId): array
    {
        return Database::getInstance()->prepare('SELECT * FROM tl_genai_chats WHERE id=?')->limit(1)->execute($intChatId)->row();
    }
}