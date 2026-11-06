---
name: hindsight-php-agent-memory
description: Add long-term memory to Laravel AI SDK agents with fedoseev/hindsight-php, store and recall memories through the Hindsight client, and test that code with Hindsight::fake(). Use when an agent should remember users across conversations, when code calls retain, recall or reflect, or when writing tests for such code.
license: MIT
metadata:
  author: Andrei Fedoseev
---

# Agent memory with hindsight-php

## When to activate

- Adding memory to a class that implements `Laravel\Ai\Contracts\Agent`.
- Code that uses `Fedoseev\Hindsight\Hindsight`, the `Hindsight` facade, `Memory`, the `Recall`/`Retain`/`Reflect` tools or the `RetainMemory` job.
- Tests for any of the above.

## Give an agent memory

Add the `Memory` middleware first in `middleware()`. Before the first step it recalls memories relevant to the user's message and adds them to the instructions; after the step that ends the run it stores the exchange.

```php
use Fedoseev\Hindsight\Laravel\Ai\Memory;

public function middleware(): array
{
    return [
        Memory::bank('support')->tags(["user:{$this->user->id}"]),
    ];
}
```

- Scope memory per user: a bank per user (`Memory::bank("user-{$id}")`) or a shared bank with `tags()`. `tags()` excludes untagged memories by default (`TagsMatch::AnyStrict`), so users do not see each other's memories.
- Memory is best effort: if Hindsight fails, the error is reported and the agent answers without memory. Add `->throwOnError()` only when a run without memory is worse than no run.
- A bank nobody has written to answers 404 on recall. The middleware and tools treat that as "no memories yet"; do not add special handling.
- Defaults are tuned for latency: recall uses `Budget::Low` and 1,024 tokens, and calls time out after 10 seconds. Change them with `recallBudget()` and `timeout()`.

## Store memories through the queue

Use `->queue()` when an exchange must not be lost while Hindsight is unavailable. The `RetainMemory` job retries with backoff and sends one operation ID with every attempt, so a retry never stores a memory twice.

```php
Memory::bank("user-{$this->user->id}")->queue();

use Fedoseev\Hindsight\Laravel\Jobs\RetainMemory;

RetainMemory::dispatch("user-{$user->id}", 'Upgraded to the Pro plan.', tags: ['billing']);
```

- `queue()` cannot be combined with `retainSynchronously()` or `using($client)`: the worker resolves the client from the container.
- Retry policy, connection and queue live in `config/hindsight.php` under `queue` (`HINDSIGHT_QUEUE_CONNECTION`, `HINDSIGHT_QUEUE`, `HINDSIGHT_QUEUE_TRIES`).

## Let the agent decide

Give the agent tools when it should choose what to look up or keep:

```php
use Fedoseev\Hindsight\Laravel\Ai\Tools\Recall;
use Fedoseev\Hindsight\Laravel\Ai\Tools\Retain;

public function tools(): iterable
{
    return [Recall::bank("user-{$this->user->id}"), Retain::bank("user-{$this->user->id}")];
}
```

`Reflect` runs the server's LLM; it fails on a Hindsight server started without an LLM provider.

## Test it without a server

```php
use Fedoseev\Hindsight\Laravel\Facades\Hindsight;

Hindsight::fake(['user-1' => ['Alice is on the Pro plan']]);
SupportAgent::fake(['Your Pro plan renews on 1 December.']);

(new SupportAgent($alice))->prompt('When does my plan renew?');

Hindsight::assertRecalled('user-1');
Hindsight::assertRetained('user-1', fn (string $content) => str_contains($content, 'renews'));
```

- `Hindsight::fake()` swaps the client in the container, so the middleware, tools and the `RetainMemory` job use it.
- The fake does not extract facts or rank results: recall returns every stored memory that passes the type and tag filters.
- `unavailable()` simulates an outage; `respondTo()` stubs endpoints the fake does not simulate.

## Setup

- `.env`: `HINDSIGHT_URL` (default `http://localhost:8888`) and `HINDSIGHT_API_KEY` for Hindsight Cloud or a secured server.
- Check the connection with `php artisan hindsight:status` (add `--bank=` to check a bank).
