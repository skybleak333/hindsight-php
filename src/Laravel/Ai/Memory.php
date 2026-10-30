<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Laravel\Ai;

use Closure;
use Fedoseev\Hindsight\Budget;
use Fedoseev\Hindsight\Exceptions\HindsightException;
use Fedoseev\Hindsight\Hindsight;
use Fedoseev\Hindsight\TagsMatch;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\StepResult;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Responses\Data\FinishReason;

/**
 * Long-term memory for a Laravel AI SDK agent.
 *
 * Before the first generation step it recalls memories relevant to the user's message and adds them to the
 * agent's instructions. After the final step it retains the exchange, so later conversations can recall it.
 *
 *     public function middleware(): array
 *     {
 *         return [Memory::bank("user-{$this->user->id}")];
 *     }
 *
 * Memory is best effort: when Hindsight is unreachable or answers with an error, the exception is reported
 * through Laravel's exception handler and the agent runs without memory. Call throwOnError() to fail instead.
 */
final class Memory
{
    public const DEFAULT_CONTEXT = 'Conversation between a user and an AI assistant';

    private bool $recall = true;

    private bool $retain = true;

    private Budget|string $budget = Budget::Low;

    private int $maxTokens = 1024;

    /** @var list<string>|null */
    private ?array $types = null;

    /** @var list<string>|null */
    private ?array $tags = null;

    private TagsMatch|string $tagsMatch = TagsMatch::Any;

    private bool $async = true;

    private ?string $context = self::DEFAULT_CONTEXT;

    private ?string $documentId = null;

    private bool $throw = false;

    private ?Hindsight $client = null;

    /** The memories recalled for the current run, re-applied to every step after the first. */
    private ?string $memories = null;

    public function __construct(private readonly string $bankId) {}

    public static function bank(string $bankId): self
    {
        return new self($bankId);
    }

    /**
     * Recall only memories with these tags, and tag the memories this agent retains.
     *
     * @param  list<string>  $tags
     */
    public function tags(array $tags, TagsMatch|string $match = TagsMatch::Any): self
    {
        $this->tags = $tags;
        $this->tagsMatch = $match;

        return $this;
    }

    /**
     * How hard recall searches and how much it may add to the instructions. Low by default to keep latency down.
     *
     * @param  list<string>|null  $types  Any of "world", "experience" and "observation". Null means all.
     */
    public function recallBudget(Budget|string $budget, int $maxTokens = 1024, ?array $types = null): self
    {
        $this->budget = $budget;
        $this->maxTokens = $maxTokens;
        $this->types = $types;

        return $this;
    }

    /**
     * Describe where the retained conversations come from. It helps the server extract better facts.
     */
    public function context(?string $context): self
    {
        $this->context = $context;

        return $this;
    }

    /**
     * Append every exchange to one document, e.g. one per conversation, instead of storing each separately.
     */
    public function document(string $documentId): self
    {
        $this->documentId = $documentId;

        return $this;
    }

    public function withoutRecall(): self
    {
        $this->recall = false;

        return $this;
    }

    public function withoutRetain(): self
    {
        $this->retain = false;

        return $this;
    }

    /**
     * Wait for the server to finish extracting facts before the run returns. Retains are async by default.
     */
    public function retainSynchronously(): self
    {
        $this->async = false;

        return $this;
    }

    public function throwOnError(bool $throw = true): self
    {
        $this->throw = $throw;

        return $this;
    }

    /**
     * Use this client instead of the one bound in the container.
     */
    public function using(Hindsight $client): self
    {
        $this->client = $client;

        return $this;
    }

    public function handle(PendingStep $step, Closure $next): mixed
    {
        $message = self::latestUserMessage($step->messages);

        if ($step->isFirstStep()) {
            $this->memories = $this->recall && $message !== '' ? $this->recallFor($message) : null;
        }

        if ($this->memories !== null) {
            $step = $step->withInstructions(ltrim(($step->instructions ?? '')."\n\n".$this->memories));
        }

        $result = $next($step);

        if ($this->retain && $message !== '' && $result instanceof StepResult) {
            $result->then(function (StepResponse $response) use ($step, $message): void {
                if (self::endsTheRun($step, $response)) {
                    $this->retainExchange($message, $response->text);
                }
            });
        }

        return $result;
    }

    private function recallFor(string $message): ?string
    {
        try {
            $memories = $this->client()->recall(
                $this->bankId,
                $message,
                types: $this->types,
                budget: $this->budget,
                maxTokens: $this->maxTokens,
                tags: $this->tags,
                tagsMatch: $this->tagsMatch,
            );
        } catch (HindsightException $exception) {
            $this->handleError($exception);

            return null;
        }

        if ($memories->isEmpty()) {
            return null;
        }

        return "# Relevant Memories\nThe following information from long-term memory may be relevant:\n\n".$memories->toPrompt();
    }

    private function retainExchange(string $message, string $answer): void
    {
        try {
            $this->client()->retain(
                $this->bankId,
                "USER: {$message}\nASSISTANT: {$answer}",
                context: $this->context,
                documentId: $this->documentId,
                tags: $this->tags,
                updateMode: $this->documentId === null ? null : 'append',
                async: $this->async,
            );
        } catch (HindsightException $exception) {
            $this->handleError($exception);
        }
    }

    private function handleError(HindsightException $exception): void
    {
        if ($this->throw) {
            throw $exception;
        }

        report($exception);
    }

    private function client(): Hindsight
    {
        return $this->client ??= app(Hindsight::class);
    }

    /**
     * A step ends the run when the model answers without asking for tools, or when no steps are left.
     */
    private static function endsTheRun(PendingStep $step, StepResponse $response): bool
    {
        if ($response->text === '' || $response->pendingApprovals !== []) {
            return false;
        }

        return ($response->toolCalls === [] && $response->finishReason !== FinishReason::Continue) || $step->isFinalStep;
    }

    /**
     * @param  array<mixed>  $messages
     */
    private static function latestUserMessage(array $messages): string
    {
        foreach (array_reverse($messages) as $message) {
            if ($message instanceof UserMessage) {
                return trim($message->content ?? '');
            }
        }

        return '';
    }
}
