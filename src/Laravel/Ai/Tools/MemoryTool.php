<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Laravel\Ai\Tools;

use Fedoseev\Hindsight\Exceptions\HindsightException;
use Fedoseev\Hindsight\Exceptions\NotFoundException;
use Fedoseev\Hindsight\Laravel\Concerns\ConfiguresMemoryTool;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Shared setup of the memory tools: which bank, which tags, which client.
 */
abstract class MemoryTool implements Tool
{
    use ConfiguresMemoryTool;

    protected ?string $name = null;

    final public function __construct(protected readonly string $bankId) {}

    public static function bank(string $bankId): static
    {
        return new static($bankId);
    }

    public function name(): string
    {
        return $this->name ?? $this->defaultName();
    }

    public function handle(Request $request): string
    {
        try {
            return $this->run($request);
        } catch (NotFoundException $exception) {
            if ($exception->isMissingBank($this->bankId)) {
                return $this->nothingStored();
            }

            report($exception);

            return "Memory is unavailable: {$exception->getMessage()}";
        } catch (HindsightException $exception) {
            report($exception);

            // Telling the model lets it answer without memory instead of ending the whole run.
            return "Memory is unavailable: {$exception->getMessage()}";
        }
    }

    abstract protected function defaultName(): string;

    /**
     * What the model is told when the bank does not exist yet because nothing was stored in it.
     */
    protected function nothingStored(): string
    {
        return 'There is nothing in memory yet.';
    }

    abstract protected function run(Request $request): string;
}
