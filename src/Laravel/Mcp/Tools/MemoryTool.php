<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Laravel\Mcp\Tools;

use Closure;
use Fedoseev\Hindsight\Exceptions\HindsightException;
use Fedoseev\Hindsight\Exceptions\NotFoundException;
use Fedoseev\Hindsight\Laravel\Concerns\ConfiguresMemoryTool;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * Shared setup of the memory tools for Laravel MCP servers: which bank, which tags, which client.
 */
abstract class MemoryTool extends Tool
{
    use ConfiguresMemoryTool;

    /**
     * @param  string|Closure(Request): string  $bank  A bank id, or a closure that picks the bank for each call, e.g. by user.
     */
    final public function __construct(protected readonly string|Closure $bank) {}

    /**
     * @param  string|Closure(Request): string  $bank
     */
    public static function bank(string|Closure $bank): static
    {
        return new static($bank);
    }

    public function name(): string
    {
        return $this->name !== '' ? $this->name : $this->defaultName();
    }

    public function handle(Request $request): Response
    {
        $bankId = $this->bank instanceof Closure ? ($this->bank)($request) : $this->bank;

        try {
            return Response::text($this->run($request, $bankId));
        } catch (NotFoundException $exception) {
            if ($exception->isMissingBank($bankId)) {
                return Response::text($this->nothingStored());
            }

            return $this->unavailable($exception);
        } catch (HindsightException $exception) {
            return $this->unavailable($exception);
        }
    }

    abstract protected function defaultName(): string;

    /**
     * What the client is told when the bank does not exist yet because nothing was stored in it.
     */
    protected function nothingStored(): string
    {
        return 'There is nothing in memory yet.';
    }

    abstract protected function run(Request $request, string $bankId): string;

    private function unavailable(HindsightException $exception): Response
    {
        report($exception);

        // The answer goes to an MCP client outside the app, so details stay in the log unless
        // the app runs in debug mode, the same way laravel/mcp treats other exceptions.
        return Response::error(config('app.debug', false)
            ? "Memory is unavailable: {$exception->getMessage()}"
            : 'Memory is unavailable right now.');
    }
}
