<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Laravel\Ai;

use Closure;
use Fedoseev\Hindsight\Budget;
use Fedoseev\Hindsight\Exceptions\HindsightException;
use Fedoseev\Hindsight\Exceptions\NotFoundException;
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
 * agent's instructions. After the step that ends the run it retains the exchange, so later conversations can
 * recall it.
 *
 *     public function middleware(): array
 *     {
 *         return [Memory::bank("user-{$this->user->id}")];
 *     }
 *
 * Memory is best effort: when Hindsight is unreachable, slow or answers with an error, the exception is
 * reported through Laravel's exception handler and the agent runs without memory. Call throwOnError() to fail
 * instead.
 *
 * Create a new instance in middleware(), as above, and list it first, so it sees the step as the model
 * finally answers it. One instance shared by runs that stream in parallel would mix up their memories.
 */
final class Memory
{
    public const DEFAULT_CONTEXT = 'Conversation between a user and an AI assistant';

    /** Seconds a recall or an async retain may take before the agent goes on without memory. */
    public const DEFAULT_TIMEOUT = 10.0;

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

    private ?float $timeout = null;

    private ?string $context = self::DEFAULT_CONTEXT;

    private ?string $documentId = null;

    private bool $throw = false;

    private ?Hindsight $client = null;

    /** The memories recalled for the run in $memoriesRun, added to every step of that run. */
    private ?string $memories = null;

    private ?string $memoriesRun = null;

    /** The run whose exchange was already retained, so a step that is generated twice is stored once. */
    private ?string $retainedRun = null;

    public function __construct(private readonly string $bankId) {}

    public static function bank(string $bankId): self
    {
        return new self($bankId);
    }

    /**
     * Recall only memories with these tags, and tag the memories this agent retains.
     *
     * Untagged memories are left out by default, so one user's tags never pull in memories stored without
     * tags. Pass TagsMatch::Any to include untagged memories too, e.g. knowledge shared by every user.
     *
     * @param  list<string>  $tags
     */
    public function tags(array $tags, TagsMatch|string $match = TagsMatch::AnyStrict): self
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

    /**
     * Seconds to wait for Hindsight before going on without memory. Defaults to 10 for recall and async
     * retains, and to the client's own timeout for synchronous retains, which wait for the server's LLM.
     */
    public function timeout(float $seconds): self
    {
        $this->timeout = $seconds;

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
        $run = $step->invocationId;

        // State is kept per run, so an instance shared between runs never hands one run's memories to another.
        if ($step->isFirstStep() && ($run === null || $run !== $this->memoriesRun)) {
            $this->memories = $this->recall && $message !== '' ? $this->recallFor($message) : null;
            $this->memoriesRun = $run;
        } elseif ($run !== $this->memoriesRun) {
            $this->memories = null;
            $this->memoriesRun = $run;
        }

        if ($this->memories !== null) {
            $step = $step->withInstructions(ltrim(($step->instructions ?? '')."\n\n".$this->memories));
        }

        $result = $next($step);

        if ($this->retain && $message !== '' && $result instanceof StepResult) {
            $result->then(function (StepResponse $response) use ($message, $run): void {
                if (! self::endsTheRun($response) || ($run !== null && $run === $this->retainedRun)) {
                    return;
                }

                $this->retainedRun = $run;
                $this->retainExchange($message, $response->text);
            });
        }

        return $result;
    }

    private function recallFor(string $message): ?string
    {
        try {
            $memories = $this->client($this->timeout ?? self::DEFAULT_TIMEOUT)->recall(
                $this->bankId,
                $message,
                types: $this->types,
                budget: $this->budget,
                maxTokens: $this->maxTokens,
                tags: $this->tags,
                tagsMatch: $this->tagsMatch,
            );
        } catch (NotFoundException $exception) {
            // Reads of a bank nobody has written to yet answer 404: a new user simply has no memories.
            if (! self::isMissingBank($exception, $this->bankId)) {
                $this->handleError($exception);
            }

            return null;
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
            $this->client($this->timeout ?? ($this->async ? self::DEFAULT_TIMEOUT : null))->retain(
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

    /**
     * A 404 from Hindsight about this bank, as opposed to one from a wrong URL or a proxy.
     *
     * Only the server's own explanation is searched for the bank ID, never the whole message.
     */
    public static function isMissingBank(NotFoundException $exception, string $bankId): bool
    {
        $detail = is_array($exception->body) ? ($exception->body['detail'] ?? null) : null;

        return is_string($detail)
            && preg_match('/(?<![\w-])'.preg_quote($bankId, '/').'(?![\w-])/', $detail) === 1;
    }

    private function handleError(HindsightException $exception): void
    {
        if ($this->throw) {
            throw $exception;
        }

        report($exception);
    }

    private function client(?float $timeout): Hindsight
    {
        $client = $this->client ??= app(Hindsight::class);

        return $timeout === null ? $client : $client->withTimeout($timeout);
    }

    /**
     * Whether the loop ends the run after this step, by the same rule as Laravel AI's TextGenerationLoop:
     * no tool runs (the model asked for none, or did not finish with tool calls) and the model is not
     * continuing. A step that asks for tools is never stored, even on the last allowed step, because a tool
     * that needs approval pauses the run there, and the resumed run would store the exchange again.
     */
    private static function endsTheRun(StepResponse $response): bool
    {
        return $response->text !== ''
            && $response->pendingApprovals === []
            && $response->finishReason !== FinishReason::Continue
            && ($response->toolCalls === [] || $response->finishReason !== FinishReason::ToolCalls);
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
