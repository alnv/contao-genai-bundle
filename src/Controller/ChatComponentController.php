<?php

namespace Alnv\GenaiBundle\Controller;

use Alnv\GenaiBundle\Entities\Assistant;
use Contao\CoreBundle\Controller\AbstractController;
use Contao\Input;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

#[Route(path: 'gen-ai/chat-component', name: 'gen-ai-controller', defaults: ['_scope' => 'frontend', '_token_check' => false])]
class ChatComponentController extends AbstractController
{

    #[Route(path: '/ask/{assistantId}', methods: ["POST", "GET"])]
    public function askChatAssistantId($assistantId): JsonResponse
    {
        $this->container->get('contao.framework')->initialize();

        $strQuestion = Input::get('question') ?: Input::post('question');

        $objAssistant = new Assistant($assistantId);

        return new JsonResponse($objAssistant->ask($strQuestion));
    }

    #[Route(path: '/conversation/{assistantId}', methods: ["POST", "GET"])]
    public function getConversation($assistantId): JsonResponse
    {
        $this->container->get('contao.framework')->initialize();

        $objAssistant = new Assistant($assistantId);

        return new JsonResponse($objAssistant->messages());
    }

    #[Route(path: '/conversation/{assistantId}/reset', methods: ["POST", "GET"])]
    public function resetConversation($assistantId): JsonResponse
    {
        $this->container->get('contao.framework')->initialize();

        $objAssistant = new Assistant($assistantId);
        $objAssistant->resetConversion();

        return new JsonResponse([]);
    }
}