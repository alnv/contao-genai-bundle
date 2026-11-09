<?php

namespace Alnv\GenaiBundle\Components;

use Alnv\GenaiBundle\Entities\Assistant;
use Contao\Combiner;
use Contao\FrontendTemplate;
use Contao\System;
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

        // Build labels with fallbacks from TL_LANG
        $labels = $this->buildLabels();

        $objTemplate = new FrontendTemplate('ce_chat_component');
        $objTemplate->setData([
            'templateId' => 'id_' . \uniqid(),
            'assistantId' => $this->objAssistant->getAssistantId(),
            'assistant' => $this->objAssistant->getAssistantArray(),
            'chatRoleAssistant' => ChatRole::Assistant?->value,
            'chatRoleUser' => ChatRole::User?->value,
            'requestToken' => System::getContainer()->get('contao.csrf.token_manager')->getDefaultTokenValue(),
            'labels' => $labels,
            'content' => [
                'headline' => $this->arrOptions['headline'] ?? '',
                'text' => $this->arrOptions['text'] ?? ''
            ]
        ]);

        return $objTemplate->parse();
    }

    /**
     * Build labels array with custom values or fallbacks
     */
    protected function buildLabels(): array
    {
        $lang = $GLOBALS['TL_LANG']['genai'] ?? [];

        return [
            'placeholder' => $this->arrOptions['placeholder'] ?: ($lang['placeholder'] ?? 'Stellen Sie Ihre Frage …'),
            'send' => $this->arrOptions['send'] ?: ($lang['send'] ?? 'Senden'),
            'stop' => $this->arrOptions['stop'] ?: ($lang['stop'] ?? 'Stoppen'),
            'newChat' => $this->arrOptions['newChat'] ?: ($lang['newChat'] ?? 'Neuer Chat'),
            'copy' => $this->arrOptions['copy'] ?: ($lang['copy'] ?? 'Kopieren'),
            'copied' => $this->arrOptions['copied'] ?: ($lang['copied'] ?? 'Kopiert!'),
            'loading' => $this->arrOptions['loading'] ?: ($lang['loading'] ?? 'Einen Moment bitte …'),
            'error' => $this->arrOptions['error'] ?: ($lang['error'] ?? 'Ein Fehler ist aufgetreten.'),
            'retry' => $this->arrOptions['retry'] ?: ($lang['retry'] ?? 'Erneut versuchen'),
            'scrollDown' => $this->arrOptions['scrollDown'] ?: ($lang['scrollDown'] ?? 'Zur neuesten Antwort'),
            'sources' => $this->arrOptions['sources'] ?: ($lang['sources'] ?? 'Quellen'),
            'you' => $this->arrOptions['you'] ?: ($lang['you'] ?? 'Sie'),
            'cancelled' => $lang['cancelled'] ?? 'Abgebrochen',
        ];
    }

    protected function setResources(): void
    {
        // CSS
        $objCombiner = new Combiner();
        $objCombiner->add('/bundles/alnvgenai/scss/chat-component.scss');
        $GLOBALS['TL_HEAD']['genai-chat-css'] = '<link href="' . $objCombiner->getCombinedFile() . '" rel="stylesheet">';

        // JavaScript (Vue 3, marked.js, DOMPurify)
        $GLOBALS['TL_HEAD']['genai-vue'] = '<script src="/bundles/alnvgenai/js/vue.global.prod.js"></script>';
        $GLOBALS['TL_HEAD']['genai-marked'] = '<script src="/bundles/alnvgenai/js/marked.js"></script>';
        $GLOBALS['TL_HEAD']['genai-dompurify'] = '<script src="/bundles/alnvgenai/js/dompurify.min.js"></script>';
    }
}
