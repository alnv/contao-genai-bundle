<?php

namespace Alnv\GenaiBundle\Controller;

use Alnv\GenaiBundle\Entities\Assistant;
use Contao\CoreBundle\Controller\AbstractController;
use Contao\Input;
use LLPhant\Chat\Enums\ChatRole;
use Michelf\MarkdownExtra;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Chat Component Controller
 * 
 * Handles chat requests with:
 * - Input validation (max 4000 chars)
 * - Error masking (no internal details to frontend)
 * - SSE streaming
 */
#[Route(path: 'gen-ai/chat-component', name: 'gen-ai-controller', defaults: ['_scope' => 'frontend'])]
class ChatComponentController extends AbstractController
{
    private const STREAM_BUFFER_SIZE = 4096;
    private const MAX_QUESTION_LENGTH = 4000;

    public function __construct(
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    #[Route(path: '/ask/{assistantId}', methods: ['POST'])]
    public function askChatAssistantId(Request $request, $assistantId): JsonResponse
    {
        $this->container->get('contao.framework')->initialize();

        $strQuestion = $this->getAndValidateQuestion($request);
        if ($strQuestion instanceof JsonResponse) {
            return $strQuestion;
        }

        try {
            $objAssistant = new Assistant($assistantId);
            return new JsonResponse($objAssistant->ask($strQuestion));
        } catch (\Exception $e) {
            $this->logError('ask', $e);
            return $this->errorResponse('Es ist ein Fehler aufgetreten. Bitte versuchen Sie es erneut.');
        }
    }

    #[Route(path: '/ask-stream/{assistantId}', methods: ['POST'])]
    public function askStreamChatAssistantId(Request $request, $assistantId): Response
    {
        $startTime = \microtime(true);

        $strQuestion = $this->getAndValidateQuestion($request);
        if ($strQuestion instanceof JsonResponse) {
            return $this->sseErrorResponse($strQuestion->getContent());
        }

        // Close session before streaming to allow parallel requests
        $session = $request->getSession();
        if ($session->isStarted()) {
            $session->save();
        }

        $this->container->get('contao.framework')->initialize();

        try {
            $assistant = new Assistant($assistantId);
        } catch (\Exception $e) {
            $this->logError('assistant_init', $e);
            return $this->sseErrorResponse('Assistent nicht verfügbar.');
        }

        $messages = [];
        $sources = [];

        $assistantData = $assistant->getAssistantArray();
        $chatObject = $assistant->getChat();

        try {
            $configStartTime = \microtime(true);
            $chat = $chatObject->getConfig($strQuestion, $messages, $sources);
            $configTime = \microtime(true) - $configStartTime;
            $this->logTiming('config_and_embedding', $configTime);
        } catch (\Exception $e) {
            $this->logError('config', $e);
            return $this->sseErrorResponse('Fehler bei der Verarbeitung.');
        }

        $response = new StreamedResponse(function () use ($chat, $chatObject, $messages, $sources, $assistantData, $startTime) {
            // Disable output buffering for real-time streaming
            while (\ob_get_level() > 0) {
                \ob_end_flush();
            }

            try {
                $llmStartTime = \microtime(true);
                $response = $chat->generateChatStreamV2($messages);
                $firstTokenTime = null;

                $answer = [
                    'content' => '',
                    'createdAt' => \time(),
                    'role' => ChatRole::Assistant->value,
                    'sources' => \array_values($sources),
                    'name' => $assistantData['assistantName'] ?? '',
                    'done' => false
                ];

                $body = $response->getBody();
                $buffer = '';

                // Detect if this is an OpenAI-style response (starts with "data: ")
                $isOpenAI = null;

                while (!$body->eof()) {
                    $chunk = $body->read(self::STREAM_BUFFER_SIZE);
                    if ($chunk === '') {
                        continue;
                    }

                    $buffer .= $chunk;

                    while (($newlinePos = \strpos($buffer, "\n")) !== false) {
                        $line = \substr($buffer, 0, $newlinePos);
                        $buffer = \substr($buffer, $newlinePos + 1);

                        $line = \trim($line);
                        if ($line === '') {
                            continue;
                        }

                        // Auto-detect format on first line
                        if ($isOpenAI === null) {
                            $isOpenAI = \str_starts_with($line, 'data:');
                        }

                        if ($isOpenAI) {
                            // OpenAI SSE format: "data: {...}" or "data: [DONE]"
                            $content = $this->parseOpenAIChunk($line);
                            if ($content === null) {
                                continue; // Skip non-content lines
                            }
                            if ($content === '[DONE]') {
                                break 2; // End of stream
                            }
                        } else {
                            // Ollama format: JSON per line
                            $content = $this->parseOllamaChunk($line);
                            if ($content === null) {
                                continue;
                            }
                            if ($content === '[DONE]') {
                                break 2;
                            }
                        }

                        if ($content === '') {
                            continue;
                        }

                        if ($firstTokenTime === null) {
                            $firstTokenTime = \microtime(true) - $llmStartTime;
                            $this->logTiming('time_to_first_token', $firstTokenTime);
                        }

                        $answer['content'] .= $content;

                        // Send token as SSE event
                        $this->sendSSE([
                            'token' => $content,
                            'content' => $answer['content'],
                            'sources' => $answer['sources'],
                            'name' => $answer['name'],
                            'role' => $answer['role'],
                            'done' => false
                        ]);
                    }
                }

                // Send final event
                $answer['content'] = MarkdownExtra::defaultTransform($answer['content']);
                $answer['done'] = true;
                $this->sendSSE($answer);

                // Save to conversation
                $chatObject->getConversation()->addMessage($answer);

                $totalTime = \microtime(true) - $startTime;
                $this->logTiming('total_request', $totalTime);

            } catch (\Exception $e) {
                $this->logError('stream', $e);
                $this->sendSSE(['error' => 'Es ist ein Fehler aufgetreten.', 'done' => true]);
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream; charset=utf-8');
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('Connection', 'keep-alive');

        return $response;
    }

    #[Route(path: '/conversation/{assistantId}', methods: ['POST'])]
    public function getConversation(Request $request, $assistantId): JsonResponse
    {
        $this->container->get('contao.framework')->initialize();

        try {
            $objAssistant = new Assistant($assistantId);
            return new JsonResponse($objAssistant->messages());
        } catch (\Exception $e) {
            $this->logError('conversation', $e);
            return $this->errorResponse('Konversation nicht verfügbar.');
        }
    }

    #[Route(path: '/conversation/{assistantId}/reset', methods: ['POST'])]
    public function resetConversation(Request $request, $assistantId): JsonResponse
    {
        $this->container->get('contao.framework')->initialize();

        try {
            $objAssistant = new Assistant($assistantId);
            $objAssistant->resetConversion();
            return new JsonResponse(['success' => true]);
        } catch (\Exception $e) {
            $this->logError('reset', $e);
            return $this->errorResponse('Zurücksetzen fehlgeschlagen.');
        }
    }

    /**
     * Get and validate question from request
     */
    private function getAndValidateQuestion(Request $request): string|JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $question = $data['question']
            ?? $request->request->get('question')
            ?? $request->query->get('question')
            ?? Input::post('question')
            ?? '';

        // Trim and validate
        $question = trim($question);

        if (empty($question)) {
            return $this->errorResponse('Bitte geben Sie eine Frage ein.', Response::HTTP_BAD_REQUEST);
        }

        if (mb_strlen($question) > self::MAX_QUESTION_LENGTH) {
            return $this->errorResponse(
                sprintf('Die Frage darf maximal %d Zeichen enthalten.', self::MAX_QUESTION_LENGTH),
                Response::HTTP_BAD_REQUEST
            );
        }

        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $question);
    }

    /**
     * Create JSON error response
     */
    private function errorResponse(string $message, int $status = Response::HTTP_INTERNAL_SERVER_ERROR): JsonResponse
    {
        return new JsonResponse(['error' => $message], $status);
    }

    /**
     * Create SSE error response
     */
    private function sseErrorResponse(string $message): StreamedResponse
    {
        $response = new StreamedResponse(function () use ($message) {
            $this->sendSSE(['error' => $message, 'done' => true]);
        });

        $response->headers->set('Content-Type', 'text/event-stream; charset=utf-8');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->setStatusCode(Response::HTTP_BAD_REQUEST);

        return $response;
    }

    /**
     * Parse OpenAI SSE chunk
     * Format: "data: {...}" or "data: [DONE]"
     * 
     * @return string|null Content token, '[DONE]' for end, or null to skip
     */
    private function parseOpenAIChunk(string $line): ?string
    {
        // Must start with "data: "
        if (!\str_starts_with($line, 'data:')) {
            return null;
        }

        $data = \trim(\substr($line, 5));

        // End of stream
        if ($data === '[DONE]') {
            return '[DONE]';
        }

        // Parse JSON
        try {
            $json = \json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return null;
        }

        return $json['choices'][0]['delta']['content'] ?? null;
    }

    /**
     * Parse Ollama streaming chunk
     * Format: {"message": {"role": "assistant", "content": "..."}, "done": false}
     * 
     * @return string|null Content token, '[DONE]' for end, or null to skip
     */
    private function parseOllamaChunk(string $line): ?string
    {
        try {
            $json = \json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return null;
        }

        // Check if stream is done
        if (($json['done'] ?? false) === true) {
            return '[DONE]';
        }

        // Get message content
        $message = $json['message'] ?? null;
        if (!\is_array($message)) {
            return null;
        }

        if (($message['role'] ?? null) !== 'assistant') {
            return null;
        }

        return $message['content'] ?? null;
    }

    /**
     * Send SSE event
     */
    private function sendSSE(array $data): void
    {
        echo 'data: ' . \json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n\n";
        \flush();
    }

    /**
     * Log timing metric
     */
    private function logTiming(string $metric, float $seconds): void
    {
        $this->logger?->debug(\sprintf('[GenAI] %s: %.3fs', $metric, $seconds));
    }

    /**
     * Log error (without exposing details to frontend)
     */
    private function logError(string $context, \Exception $e): void
    {
        $this->logger?->error(\sprintf(
            '[GenAI] Error in %s: %s in %s:%d',
            $context,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));
    }
}
