<?php

namespace Alnv\GenaiBundle\ContentElement;

use Alnv\GenaiBundle\Components\ChatComponent;
use Contao\ContentElement;
use Contao\StringUtil;
use Contao\System;
use Symfony\Component\HttpFoundation\Request;

/**
 * Content element for embedding the GenAI chat widget.
 */
class ChatElement extends ContentElement
{
    protected $strTemplate = 'ce_genai_chat';

    /**
     * Generate the content element
     */
    protected function compile(): void
    {
        // Skip in backend
        if ($this->isBackendRequest()) {
            $this->Template->isBackend = true;
            $this->Template->assistantId = $this->genai_assistant;
           return;
        }

        if (!$this->genai_assistant) {
            $this->Template->content = '';
           return;
        }

        $options = [
            'headline' => $this->headline ? StringUtil::deserialize($this->headline)['value'] ?? '' : '',
            'text' => $this->text ?: '',
            // Labels from content element (empty strings will use fallbacks)
            'placeholder' => $this->genai_placeholder ?: '',
            'send' => $this->genai_send_label ?: '',
            'stop' => $this->genai_stop_label ?: '',
            'newChat' => $this->genai_new_chat_label ?: '',
            'copy' => $this->genai_copy_label ?: '',
            'copied' => $this->genai_copied_label ?: '',
            'loading' => $this->genai_loading_label ?: '',
            'error' => $this->genai_error_label ?: '',
            'retry' => $this->genai_retry_label ?: '',
            'sources' => $this->genai_sources_label ?: '',
            'you' => $this->genai_user_label ?: '',
        ];

        $chat = new ChatComponent((int) $this->genai_assistant, $options);
        $this->Template->content = $chat->generate();
    }

    /**
     * Check if current request is a backend request
     */
    protected function isBackendRequest(): bool
    {
        $container = System::getContainer();
        $requestStack = $container->get('request_stack');
        $scopeMatcher = $container->get('contao.routing.scope_matcher');
        
        $request = $requestStack->getCurrentRequest() ?? Request::create('');
        
        return $scopeMatcher->isBackendRequest($request);
    }
}
