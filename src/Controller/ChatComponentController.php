<?php

namespace Alnv\GenaiBundle\Controller;

use Alnv\GenaiBundle\Entities\Assistant;
use Contao\CoreBundle\Controller\AbstractController;
use Contao\Input;
use LLPhant\Chat\Enums\ChatRole;
use LLPhant\Utility;
use Michelf\MarkdownExtra;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Annotation\Route;

#[Route(path: 'gen-ai/chat-component', name: 'gen-ai-controller', defaults: ['_scope' => 'frontend', '_token_check' => false])]
class ChatComponentController extends AbstractController
{

    #[Route(path: '/ask/{assistantId}', methods: ["POST"])]
    public function askChatAssistantId($assistantId): JsonResponse
    {
        $this->container->get('contao.framework')->initialize();

        $strQuestion = Input::get('question') ?: Input::post('question');

        $objAssistant = new Assistant($assistantId);

        return new JsonResponse($objAssistant->ask($strQuestion));
    }

    #[Route(path: '/ask-stream/{assistantId}', methods: ["POST"])]
    public function askStreamChatAssistantId(Request $request, $assistantId): StreamedResponse
    {
        $data = json_decode($request->getContent(), true);
        $strQuestion = $data['question']
            ?? $request->request->get('question')
            ?? $request->query->get('question');

        if (empty($strQuestion)) {
            return new StreamedResponse(function () {
                echo "Fehler: Keine Frage übergeben.";
            }, 400);
        }

        $assistant = new Assistant($assistantId);
        $messages = [];
        $sources = [];

        $assistantData = $assistant->getAssistantArray();
        $chatObject = $assistant->getChat();
        $chat = $chatObject->getConfig($strQuestion, $messages, $sources);

        $response = new StreamedResponse(function () use ($chat, $chatObject, $assistant, $messages, $sources, $assistantData) {
            try {
                $response = $chat->generateChatStreamV2($messages);

                $answer = [
                    'content' => '',
                    'createdAt' => \time(),
                    'role' => ChatRole::Assistant->value,
                    'sources' => \array_values($sources),
                    'name' => $assistantData['assistantName'] ?? ''
                ];

                $body = $response->getBody();
                while (!$body->eof()) {
                    $line = $this->readLineFromStream($body);
                    if ($line === '') {
                        continue;
                    }

                    $json = Utility::decodeJson($line);

                    if (($json['done'] ?? false) === true) {
                        break;
                    }

                    $message = $json['message'] ?? null;
                    if (!is_array($message)) {
                        continue;
                    }

                    if (($message['role'] ?? null) !== 'assistant') {
                        continue;
                    }

                    $content = $message['content'] ?? '';

                    if ($content === '') {
                        continue;
                    }

                    $answer['content'] .= $content;

                    echo json_encode(
                        $answer, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";

                    if (ob_get_level() > 0) {
                        @ob_flush();
                    }

                    flush();
                }

                $answer['content'] = MarkdownExtra::defaultTransform($answer['content']);
                $chatObject->getConversation()->addMessage($answer);
            } catch (\Exception $e) {
                echo "\n[Streaming-Fehler: " . $e->getMessage() . "]";
            }
        });

        $response->headers->set('Content-Type', 'text/plain; charset=utf-8');
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    private function readLineFromStream(StreamInterface $stream): string
    {
        $buffer = '';
        while (!$stream->eof()) {
            if ('' === ($byte = $stream->read(1))) {
                return $buffer;
            }
            $buffer .= $byte;
            if ($byte === "\n") {
                break;
            }
        }

        return $buffer;
    }

    #[Route(path: '/conversation/{assistantId}', methods: ["POST"])]
    public function getConversation($assistantId): JsonResponse
    {
        $this->container->get('contao.framework')->initialize();

        $objAssistant = new Assistant($assistantId);

        return new JsonResponse($objAssistant->messages());
    }

    #[Route(path: '/conversation/{assistantId}/reset', methods: ["POST"])]
    public function resetConversation($assistantId): JsonResponse
    {
        $this->container->get('contao.framework')->initialize();

        $objAssistant = new Assistant($assistantId);
        $objAssistant->resetConversion();

        return new JsonResponse([]);
    }
}