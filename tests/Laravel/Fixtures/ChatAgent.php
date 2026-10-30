<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Tests\Laravel\Fixtures;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Promptable;

/**
 * An agent that carries earlier messages of the conversation.
 */
final class ChatAgent implements Agent, Conversational, HasMiddleware, HasTools
{
    use Promptable;

    /**
     * @param  list<mixed>  $middleware
     * @param  list<mixed>  $tools
     */
    public function __construct(public array $middleware = [], public array $tools = []) {}

    public function instructions(): string
    {
        return 'You are a support agent.';
    }

    public function messages(): iterable
    {
        return [new UserMessage('Earlier question about invoices'), new AssistantMessage('Earlier answer about invoices')];
    }

    public function middleware(): array
    {
        return $this->middleware;
    }

    public function tools(): iterable
    {
        return $this->tools;
    }
}
