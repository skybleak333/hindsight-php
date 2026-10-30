# Hindsight for PHP and Laravel

[![tests](https://github.com/skybleak333/hindsight-php/actions/workflows/tests.yml/badge.svg)](https://github.com/skybleak333/hindsight-php/actions/workflows/tests.yml)
[![PHP](https://img.shields.io/badge/php-8.2%2B-777bb4)](composer.json)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue)](LICENSE)

A PHP client for [Hindsight](https://github.com/vectorize-io/hindsight), the open-source memory server for AI agents, and long-term memory for [Laravel AI SDK](https://github.com/laravel/ai) agents.

A Laravel AI agent forgets everything when the conversation ends. With one middleware it remembers: before it answers, it recalls what it knows about the user; after it answers, it stores the exchange.

```php
use Fedoseev\Hindsight\Laravel\Ai\Memory;

class SupportAgent implements Agent, HasMiddleware
{
    use Promptable;

    public function __construct(private User $user) {}

    public function instructions(): string
    {
        return 'You are a friendly support agent.';
    }

    public function middleware(): array
    {
        return [Memory::bank("user-{$this->user->id}")];
    }
}
```

Monday: *"I'm on the Pro plan and I prefer short answers."* Friday, in a new conversation: *"When does my plan renew?"* The agent already knows the plan and answers briefly.

This is a community package. It is not affiliated with Vectorize, the company behind Hindsight.

## Contents

- [Installation](#installation)
- [Run a Hindsight server](#run-a-hindsight-server)
- [The client](#the-client)
- [Laravel](#laravel)
- [Memory for Laravel AI SDK agents](#memory-for-laravel-ai-sdk-agents)
- [Errors](#errors)
- [Testing your application](#testing-your-application)
- [How this package is tested](#how-this-package-is-tested)
- [Compatibility](#compatibility)

## Installation

```bash
composer require fedoseev/hindsight-php
```

The client needs PHP 8.2+ and works in any PHP project. The Laravel integration is discovered automatically. The agent memory needs [`laravel/ai`](https://github.com/laravel/ai) 1.x and PHP 8.3+.

## Run a Hindsight server

The quickest way is Docker. It starts the API on port 8888 and the web UI on port 9999:

```bash
docker run -it --pull always --name hindsight -p 8888:8888 -p 9999:9999 \
  -e HINDSIGHT_API_LLM_API_KEY=$OPENAI_API_KEY \
  -v hindsight-data:/home/hindsight/.pg0 \
  ghcr.io/vectorize-io/hindsight:latest
```

Hindsight uses an LLM to turn what you store into facts. It supports OpenAI, Anthropic, Gemini, Groq, Ollama and many other providers. See the [installation guide](https://hindsight.vectorize.io/developer/installation) for all options, or use [Hindsight Cloud](https://vectorize.io/pricing) at `https://api.hindsight.vectorize.io`.

## The client

```php
use Fedoseev\Hindsight\Hindsight;

$hindsight = new Hindsight('http://localhost:8888', apiKey: getenv('HINDSIGHT_API_KEY') ?: null);

// Store a memory. The server extracts facts, entities and dates from it.
$hindsight->retain('my-bank', 'Alice works at Google as a software engineer.');

// Find what is relevant to a question.
$memories = $hindsight->recall('my-bank', 'What does Alice do?');

foreach ($memories as $memory) {
    echo $memory->text;   // "Alice works at Google as a software engineer"
}

// Let the server reason over everything it remembers.
echo $hindsight->reflect('my-bank', 'Tell me about Alice');
```

Banks are created on first use. Method names and arguments follow the official [Python and TypeScript clients](https://hindsight.vectorize.io/sdks/python), so the Hindsight documentation applies directly.

### Retain

```php
use Fedoseev\Hindsight\Hindsight;

$hindsight->retain(
    'support',
    'The customer asked to move the delivery to Friday.',
    timestamp: new DateTimeImmutable('2026-10-28 14:00'),
    context: 'support chat',
    documentId: 'ticket-4521',
    tags: ['user:42'],
    metadata: ['channel' => 'chat'],
);

// Many items in one request. Keys can be camelCase or the API's snake_case.
$hindsight->retainBatch('support', [
    ['content' => 'Prefers email over phone.', 'tags' => ['user:42']],
    ['content' => 'Lives in Rotterdam.', 'tags' => ['user:42'], 'documentId' => 'profile-42'],
]);
```

Retain calls the server's LLM, so it can take a few seconds. Pass `async: true` to return at once and let the server work in the background:

```php
$response = $hindsight->retain('support', $transcript, async: true);

// Later, or in a queued job:
$operation = $hindsight->waitForOperation('support', $response->operationId, timeout: 60);
```

`waitForOperation()` throws `OperationFailedException` if the operation fails and `TimeoutException` if it is still running after the timeout.

### Recall

```php
use Fedoseev\Hindsight\Budget;
use Fedoseev\Hindsight\TagsMatch;

$memories = $hindsight->recall(
    'support',
    'How does this customer want to be contacted?',
    budget: Budget::High,          // how hard to search: Low, Mid (default) or High
    maxTokens: 2048,               // upper bound for the size of the results
    types: ['world', 'experience'],
    tags: ['user:42'],
    tagsMatch: TagsMatch::AnyStrict, // only memories tagged user:42
);

$memories->texts();     // ['Prefers email over phone', ...]
$memories->toPrompt();  // "- Prefers email over phone\n- Lives in Rotterdam" for an LLM prompt
$memories->isEmpty();

$first = $memories->results[0];
$first->id; $first->text; $first->type; $first->occurredStart; $first->documentId; $first->tags;
```

Any other recall option goes into `options`, with the API's field names: `options: ['include' => ['chunks' => ['max_tokens' => 2000]]]`.

### Reflect

```php
$answer = $hindsight->reflect('support', 'Summarise this customer', tags: ['user:42'], includeFacts: true);

$answer->text;     // Markdown
$answer->basedOn;  // the memories the answer used

// Structured output
$answer = $hindsight->reflect('support', 'Where does the customer live?', responseSchema: [
    'type' => 'object',
    'properties' => ['city' => ['type' => 'string']],
    'required' => ['city'],
]);

$answer->structuredOutput; // ['city' => 'Rotterdam']
```

### Everything else

| Area | Methods |
|---|---|
| Banks | `listBanks`, `createBank`, `updateBank`, `getBankProfile`, `getBankStats`, `deleteBank`, `getBankConfig`, `updateBankConfig`, `resetBankConfig` |
| Memories | `listMemories`, `getMemory`, `clearMemories` |
| Documents | `listDocuments`, `getDocument`, `deleteDocument` |
| Directives | `createDirective`, `listDirectives`, `getDirective`, `updateDirective`, `deleteDirective` |
| Mental models | `createMentalModel`, `listMentalModels`, `getMentalModel`, `updateMentalModel`, `deleteMentalModel`, `refreshMentalModel` |
| Operations | `getOperation`, `listOperations`, `cancelOperation`, `waitForOperation` |
| Server | `health`, `version` |

For any endpoint without its own method, use `request()`. It returns the decoded JSON:

```php
$tags = $hindsight->request('GET', '/v1/default/banks/support/tags', query: ['limit' => 50]);
```

The client accepts any [PSR-18](https://www.php-fig.org/psr/psr-18/) HTTP client. Without one it uses Guzzle:

```php
$hindsight = new Hindsight('https://api.hindsight.vectorize.io', apiKey: $key, httpClient: $myPsr18Client);
```

### Typos in options do not go unnoticed

Hindsight accepts parameters it does not know, ignores them and answers `200`. A misspelled option such as `max_token` therefore changes the result without any error. The server does name the ignored parameters in an `X-Ignored-Params` response header, and this client turns that header into a warning for any [PSR-3](https://www.php-fig.org/psr/psr-3/) logger:

```php
$hindsight = new Hindsight('http://localhost:8888', logger: $logger);

$hindsight->recall('support', 'plan', options: ['max_token' => 500]);
// warning: Hindsight ignored unknown parameters: max_token (POST /v1/default/banks/support/memories/recall)
```

In Laravel the warning goes to your default log channel without any setup.

## Laravel

Add the server to `.env`:

```dotenv
HINDSIGHT_URL=http://localhost:8888
HINDSIGHT_API_KEY=
```

Check the connection:

```bash
php artisan hindsight:status
php artisan hindsight:status --bank=support
```

The client is a singleton in the container. Inject `Fedoseev\Hindsight\Hindsight` or use the facade:

```php
use Fedoseev\Hindsight\Laravel\Facades\Hindsight;

Hindsight::retain("user-{$user->id}", 'Upgraded to the Pro plan.', tags: ['billing']);
```

To change the timeout or add headers, publish the config:

```bash
php artisan vendor:publish --tag=hindsight-config
```

To add retries, a proxy or logging, bind your own PSR-18 client as `hindsight.http` before the client is first used.

## Memory for Laravel AI SDK agents

### The middleware

`Memory` is an [agent middleware](https://laravel.com/docs/ai-sdk). On every run it does two things:

1. **Before the first step**, it recalls memories relevant to the user's message and adds them to the agent's instructions:

   ```
   You are a friendly support agent.

   # Relevant Memories
   The following information from long-term memory may be relevant:

   - Alice is on the Pro plan (2026-09-01)
   - Alice prefers short answers
   ```

   The memories stay in the instructions for every step of the run, including the steps after tool calls.

2. **After the final step**, it retains the exchange as `USER: … ASSISTANT: …`, the same format as Hindsight's own LiteLLM integration. Steps that only call tools are not stored. Streamed answers are stored once the stream has been read.

```php
public function middleware(): array
{
    return [
        Memory::bank("user-{$this->user->id}")
            ->recallBudget(Budget::Mid, maxTokens: 2048) // default: Low, 1024 tokens
            ->tags(["team:{$this->user->team_id}"])      // scope recall and tag what is stored
            ->document("conversation-{$this->conversationId}") // append each exchange to one document
            ->context('Support chat on example.com'),     // helps the server extract better facts
    ];
}
```

| Method | What it does |
|---|---|
| `recallBudget($budget, $maxTokens, $types)` | How hard recall searches and how much it may add. Default `Budget::Low` and 1,024 tokens, to keep latency down. |
| `tags($tags, $match)` | Recall only these tags and tag the stored exchanges. |
| `document($id)` | Append every exchange to one document (`update_mode: append`) instead of storing each separately. |
| `context($text)` | Describe the source of the conversations. |
| `withoutRecall()` / `withoutRetain()` | Use only one half. |
| `retainSynchronously()` | Wait for fact extraction. By default retains are async, so the user does not wait. |
| `throwOnError()` | Fail the run when Hindsight fails. See below. |
| `using($client)` | Use a specific client instead of the container's. |

Memory is best effort by default. If Hindsight is down or answers with an error, the middleware reports the exception through Laravel's exception handler and the agent answers without memory. Call `throwOnError()` if a run without memory is worse than no run.

### The tools

Sometimes the agent should decide itself when to look something up or what is worth keeping. Give it the tools:

```php
use Fedoseev\Hindsight\Laravel\Ai\Tools\Recall;
use Fedoseev\Hindsight\Laravel\Ai\Tools\Reflect;
use Fedoseev\Hindsight\Laravel\Ai\Tools\Retain;

public function tools(): iterable
{
    $bank = "user-{$this->user->id}";

    return [
        Recall::bank($bank),   // recall_memory: search memory
        Retain::bank($bank),   // retain_memory: store a fact the agent finds important
        Reflect::bank($bank),  // reflect_memory: reason over everything in memory
    ];
}
```

Each tool takes `tags()`, `using()` and `named()` (to rename it, for example when an agent has tools for two banks). `Recall` and `Reflect` take `budget()`. When Hindsight fails, a tool returns the error to the model instead of ending the run, so the agent can still answer.

The middleware and the tools work together. A common setup is the middleware for automatic memory plus `Retain` for facts the agent should keep on purpose.

## Errors

Every exception implements `Fedoseev\Hindsight\Exceptions\HindsightException`.

| Exception | When |
|---|---|
| `ConnectionException` | The server could not be reached. |
| `AuthenticationException` | 401 or 403: the API key is missing or wrong. |
| `NotFoundException` | 404: the bank, document or other resource does not exist. |
| `ValidationException` | 422: the server rejected the request. `errors()` lists the fields. |
| `ApiException` | Any other error status. Has `statusCode` and `body`. The three above extend it. |
| `UnexpectedResponseException` | A success status with a body that is not JSON, for example when the URL points at the web UI. |
| `OperationFailedException`, `TimeoutException` | From `waitForOperation()`. |

The message always contains the server's explanation:

```
Hindsight API error 422: body.items.0.content: Field required
```

## Testing your application

Bind a fake PSR-18 client, for example Guzzle's `MockHandler`, before the client is first used, and nothing leaves your test:

```php
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

$mock = new MockHandler([
    new Response(200, [], json_encode(['results' => [['id' => 'f1', 'text' => 'Alice is on the Pro plan']]])),
    new Response(200, [], json_encode(['success' => true, 'bank_id' => 'user-1', 'items_count' => 1, 'async' => true])),
]);

$this->app->instance('hindsight.http', new Client(['handler' => HandlerStack::create($mock)]));
```

Combine it with `SupportAgent::fake([...])` from the Laravel AI SDK to test an agent with memory without any network calls.

## How this package is tested

- **Every request in the unit tests is checked against Hindsight's own [OpenAPI document](tests/Fixtures/openapi-1.1.0.json)**, downloaded from the `v1.1.0` tag. The check is stricter than the server: Hindsight answers `200` for unknown fields, so a misspelled field name in the client would never fail a request. The tests reject it. Response fixtures are checked against the same document.
- **CI starts a real Hindsight 1.1.0 server** (`hindsight-api-slim` with local ONNX embeddings and no LLM) and runs the integration suite against it, including a Laravel AI agent that remembers a fact from one conversation in the next. The job runs on every push and weekly, so a new Hindsight release that breaks something shows up quickly.
- PHPStan at level 9. The client reads response fields without trusting their types, so an unexpected value from the server becomes `null` instead of a `TypeError`.

Without an LLM the server cannot run reflect or mental models, so those are covered by the OpenAPI-checked unit tests only.

## Compatibility

| | Versions | Tested in CI |
|---|---|---|
| PHP | 8.2+ (client), 8.3+ (Laravel AI) | 8.2, 8.3, 8.4, 8.5 |
| Laravel | 12, 13 | 12, 13 |
| Laravel AI SDK | 1.x | latest and lowest allowed |
| Hindsight API | 1.1 | 1.1.0 |

## Contributing

Issues and pull requests are welcome. Run the tests with `composer test` and PHPStan with `composer analyse`. To run the integration suite, start a Hindsight server on `127.0.0.1:8888` and run `composer test:integration`. The integration tests create and delete their own banks and refuse to run against any host other than localhost.

## License

MIT. See [LICENSE](LICENSE).
