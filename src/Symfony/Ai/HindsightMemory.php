<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Symfony\Ai;

use Closure;
use Fedoseev\Hindsight\Budget;
use Fedoseev\Hindsight\Exceptions\HindsightException;
use Fedoseev\Hindsight\Exceptions\NotFoundException;
use Fedoseev\Hindsight\Hindsight;
use Fedoseev\Hindsight\TagsMatch;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\Input;
use Symfony\AI\Agent\Memory\Memory;
use Symfony\AI\Agent\Memory\MemoryProviderInterface;
use Symfony\AI\Agent\Output;
use Symfony\AI\Agent\OutputProcessorInterface;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\Result\MultiPartResult;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\TextResult;

/**
 * Long-term memory for Symfony AI agents: recalls what the bank knows about the
 * user's message before the model answers, and retains the exchange after.
 *
 * Register the same instance as a memory provider and as an output processor:
 *
 *     $memory = new HindsightMemory($client, 'user-42');
 *     $agent = new Agent($platform, $model, [new MemoryInputProcessor([$memory])], [$memory]);
 *
 * Errors of the Hindsight API are logged and the agent answers without memory,
 * unless $throw is set.
 */
final class HindsightMemory implements MemoryProviderInterface, OutputProcessorInterface
{
    public const DEFAULT_CONTEXT = 'Conversation between a user and an AI assistant';

    public const DEFAULT_TIMEOUT = 10.0;

    /**
     * @param  string|Closure(): string  $bank  the bank ID, or a closure that returns it for the current user
     * @param  list<string>|null  $tags  tags given to retained exchanges and required on recalled memories
     * @param  list<string>|null  $types  the memory types to recall (world, experience, observation)
     * @param  bool  $async  retain without waiting for Hindsight to extract the facts
     * @param  float|null  $timeout  seconds for each call; by default 10 for recall and an async retain,
     *                               and the client's own timeout for a retain that waits for the facts
     */
    public function __construct(
        private readonly Hindsight $client,
        private readonly string|Closure $bank,
        private readonly ?array $tags = null,
        private readonly TagsMatch|string $tagsMatch = TagsMatch::AnyStrict,
        private readonly Budget|string $budget = Budget::Low,
        private readonly int $maxTokens = 1024,
        private readonly ?array $types = null,
        private readonly ?string $context = self::DEFAULT_CONTEXT,
        private readonly bool $recall = true,
        private readonly bool $retain = true,
        private readonly bool $async = true,
        private readonly ?float $timeout = null,
        private readonly bool $throw = false,
        private readonly LoggerInterface $logger = new NullLogger,
    ) {}

    /**
     * @return list<Memory>
     */
    public function load(Input $input): array
    {
        $message = self::latestUserMessage($input->getMessageBag());

        if (! $this->recall || $message === '') {
            return [];
        }

        $bankId = $this->bankId();

        try {
            $memories = $this->client($this->timeout ?? self::DEFAULT_TIMEOUT)->recall(
                $bankId,
                $message,
                types: $this->types,
                budget: $this->budget,
                maxTokens: $this->maxTokens,
                tags: $this->tags,
                // The match mode only means something with tags, so without them the API default is sent.
                tagsMatch: $this->tags === null ? TagsMatch::Any : $this->tagsMatch,
            );
        } catch (NotFoundException $exception) {
            // Reads of a bank nobody has written to yet answer 404: a new user simply has no memories.
            if (! $exception->isMissingBank($bankId)) {
                $this->handleError($exception, 'recall');
            }

            return [];
        } catch (HindsightException $exception) {
            $this->handleError($exception, 'recall');

            return [];
        }

        if ($memories->isEmpty()) {
            return [];
        }

        return [new Memory("From long-term memory:\n".$memories->toPrompt())];
    }

    public function processOutput(Output $output): void
    {
        if (! $this->retain) {
            return;
        }

        // The agent's runner has already read a streamed answer and run the tool calls, so this is the final result.
        $answer = self::answerText($output->getResult());

        if (trim($answer) === '') {
            return;
        }

        $message = self::latestUserMessage($output->getMessageBag());

        if ($message === '') {
            return;
        }

        try {
            $this->client($this->timeout ?? ($this->async ? self::DEFAULT_TIMEOUT : null))->retain(
                $this->bankId(),
                "USER: {$message}\nASSISTANT: {$answer}",
                context: $this->context,
                tags: $this->tags,
                async: $this->async,
            );
        } catch (HindsightException $exception) {
            $this->handleError($exception, 'retain');
        }
    }

    private function bankId(): string
    {
        return is_string($this->bank) ? $this->bank : ($this->bank)();
    }

    private function client(?float $timeout): Hindsight
    {
        return $timeout === null ? $this->client : $this->client->withTimeout($timeout);
    }

    private function handleError(HindsightException $exception, string $operation): void
    {
        if ($this->throw) {
            throw $exception;
        }

        $this->logger->warning('Hindsight {operation} failed, the agent goes on without it: {message}', [
            'operation' => $operation,
            'message' => $exception->getMessage(),
            'exception' => $exception,
        ]);
    }

    /**
     * The text of the answer: a model that also returns reasoning or several parts answers with a MultiPartResult.
     * A result that still asks for tool calls has no answer yet.
     */
    private static function answerText(ResultInterface $result): string
    {
        return match (true) {
            $result instanceof TextResult => $result->getContent(),
            $result instanceof MultiPartResult && $result->asToolCallResult() === null => $result->asText(),
            default => '',
        };
    }

    private static function latestUserMessage(MessageBag $messages): string
    {
        foreach (array_reverse($messages->getMessages()) as $message) {
            if ($message instanceof UserMessage) {
                return trim($message->asText() ?? '');
            }
        }

        return '';
    }
}
