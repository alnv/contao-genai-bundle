<?php

namespace Alnv\GenaiBundle\Components;

use Alnv\GenaiBundle\Entities\Assistant;
use Contao\Combiner;
use Contao\FrontendTemplate;
use LLPhant\Chat\Enums\ChatRole;

class ChatComponent
{

    protected Assistant $objAssistant;

    protected array $arrOptions = [];

    public function __construct($intAssistantsId, $arrOptions = [])
    {
        $this->objAssistant = new Assistant((int)$intAssistantsId);
        $this->arrOptions = $arrOptions;
    }

    public function generate(): string
    {

        $this->setResources();

        $objTemplate = new FrontendTemplate('ce_chat_component');
        $objTemplate->setData([
            'templateId' => 'id_' . \uniqid(),
            'assistantId' => $this->objAssistant->getAssistantId(),
            'assistant' => $this->objAssistant->getAssistantArray(),
            'chatRoleAssistant' => ChatRole::Assistant?->value,
            'chatRoleUser' => ChatRole::User?->value,
            'content' => [
                'headline' => $this->arrOptions['headline'] ?? '',
                'text' => $this->arrOptions['text'] ?? ''
            ]
        ]);

        return $objTemplate->parse();
    }

    protected function setResources(): void
    {

        $objCombiner = new Combiner();
        $objCombiner->add('/bundles/alnvgenai/scss/chat-component.scss');

        $GLOBALS['TL_HEAD']['chat-component'] = '<link href="' . $objCombiner->getCombinedFile() . '" rel="stylesheet">';

        if (!isset($GLOBALS['TL_HEAD']['vue'])) {
            $objCombiner = new Combiner();
            $objCombiner->add('/bundles/alnvgenai/js/vue.min.js');
            $objCombiner->add('/bundles/alnvgenai/js/vue-resource.min.js');

            $GLOBALS['TL_HEAD']['vue'] = '<script src="' . $objCombiner->getCombinedFile() . '"></script>';
        }
    }
}