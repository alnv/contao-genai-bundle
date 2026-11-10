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
        $opts = $this->arrOptions;

        return [
            'placeholder' => ($opts['placeholder'] ?? '') ?: ($lang['placeholder'] ?? 'Stellen Sie Ihre Frage …'),
            'send' => ($opts['send'] ?? '') ?: ($lang['send'] ?? 'Senden'),
            'stop' => ($opts['stop'] ?? '') ?: ($lang['stop'] ?? 'Stoppen'),
            'newChat' => ($opts['newChat'] ?? '') ?: ($lang['newChat'] ?? 'Neuer Chat'),
            'copy' => ($opts['copy'] ?? '') ?: ($lang['copy'] ?? 'Kopieren'),
            'copied' => ($opts['copied'] ?? '') ?: ($lang['copied'] ?? 'Kopiert!'),
            'loading' => ($opts['loading'] ?? '') ?: ($lang['loading'] ?? 'Einen Moment bitte …'),
            'error' => ($opts['error'] ?? '') ?: ($lang['error'] ?? 'Ein Fehler ist aufgetreten.'),
            'retry' => ($opts['retry'] ?? '') ?: ($lang['retry'] ?? 'Erneut versuchen'),
            'scrollDown' => ($opts['scrollDown'] ?? '') ?: ($lang['scrollDown'] ?? 'Zur neuesten Antwort'),
            'sources' => ($opts['sources'] ?? '') ?: ($lang['sources'] ?? 'Quellen'),
            'you' => ($opts['you'] ?? '') ?: ($lang['you'] ?? 'Sie'),
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
