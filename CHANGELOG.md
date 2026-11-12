# Changelog

All notable changes to this package are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package uses [Semantic Versioning](https://semver.org/).

## [0.9.0] - 2026-11-12

### Added

- Memory tools for Symfony AI agents: `Recall` (`recall_memory`), `Retain` (`retain_memory`) and `Reflect` (`reflect_memory`) in `Fedoseev\Hindsight\Symfony\Ai\Tools`, with `#[AsTool]` and described parameters, so the model decides when to recall or store. Same texts and defaults as the Laravel AI SDK tools; Hindsight errors become a tool result and a warning in the logger you pass.

### Changed

- `HindsightMemory` no longer sends `tags_match` when it has no `tags`. The server ignores it without tags, so recalls return the same memories.

## [0.8.0] - 2026-11-12

### Added

- Long-term memory for [Symfony AI](https://github.com/symfony/ai) agents: `Fedoseev\Hindsight\Symfony\Ai\HindsightMemory` is a memory provider that recalls for the user's latest message and an output processor that retains the exchange, including streamed answers, answers after tool calls and answers with reasoning. It takes the bank as a string or a closure, and most options of the Laravel middleware as named arguments (no `document()` or `queue()`). Hindsight errors go to the PSR-3 logger you pass unless `throw` is set. Unit tests run with `symfony/ai-agent` 0.15 and 1.0.0-BETA1, and the integration tests with 0.15 against real Hindsight servers.

## [0.7.0] - 2026-11-11

### Added

- `SearchPages` and `ReadPage` for Laravel AI SDK agents too, in `Fedoseev\Hindsight\Laravel\Ai\Tools`, with the same tag check as the MCP tools.
- `TagsMatch::matches()`: whether tags pass a filter by the server's rules. The fake and both `ReadPage` tools use it.

### Fixed

- The MCP `ReadPage` refused every page when it was given `tags([])`. An empty filter lets everything through, as on the server.

## [0.6.0] - 2026-11-11

### Added

- MCP tools for pages: `SearchPages` (`search_pages`) and `ReadPage` (`read_page`) in `Fedoseev\Hindsight\Laravel\Mcp\Tools`, for Hindsight 1.2.1 and later. A `ReadPage` limited with `tags()` follows Hindsight's tag rules, so a client cannot read a page outside those tags by asking for its id.

## [0.5.0] - 2026-11-11

Tested against Hindsight 1.1.0, 1.2.0 and 1.2.1.

### Added

- The pages API of Hindsight 1.2.1: `getPageTree()`, `createPage()`, `createPageFolder()`, `getPage()`, `searchPages()`, `exportPages()`, `updatePageNode()` and `deletePageNode()`, also on the facade. Requests to these endpoints are checked against the 1.2.1 OpenAPI document, and CI runs the page tree against a real 1.2.1 server (creating a page needs the server's LLM, so pages are covered by the unit tests). `Hindsight::fake()` does not simulate pages; stub them with `respondTo()`.
- `NotFoundException::isMissingBank()`: whether a 404 says that the bank does not exist. `Memory::isMissingBank()` now calls it, and the MCP tools no longer use a class from the Laravel AI SDK integration.

## [0.4.0] - 2026-11-11

Tested against Hindsight 1.1.0, 1.2.0 and 1.2.1, and Laravel MCP 1.0 to 1.2.

### Added

- Memory tools for [Laravel MCP](https://github.com/laravel/mcp) servers: `Recall`, `Retain` and `Reflect` in `Fedoseev\Hindsight\Laravel\Mcp\Tools`. They take the same options as the Laravel AI SDK tools, and `bank()` also takes a closure that picks the bank from the MCP request, for example by user. Arguments are validated, and the tools describe themselves to the client with read-only and non-destructive hints. When Hindsight fails, the client gets an error result; the exception message is shown only in debug mode.

### Changed

- CI also runs the integration suite against Hindsight 1.2.1. Real servers may answer fields the OpenAPI document does not name; the fake still may not.

## [0.3.0] - 2026-11-06

### Added

- A Laravel Boost guideline and the `hindsight-php-agent-memory` agent skill. `php artisan boost:install` offers them under third-party packages (checked with Boost 2.10.4).

### Fixed

- The package can be installed in a new Laravel 13 application: it allows Guzzle 8, which Laravel 13.40 installs by default. Before, Composer refused `fedoseev/hindsight-php` there unless Guzzle was downgraded. CI now runs on Guzzle 8 and keeps a Guzzle 7 job.

## [0.2.0] - 2026-11-06

Tested against Hindsight 1.1.0 and 1.2.0, Laravel 12 and 13, and Laravel AI SDK 1.0 to 1.3.

### Added

- `Hindsight::fake()` and `Fedoseev\Hindsight\Testing\HindsightFake`: an in-memory server for tests, with `assertRetained()`, `assertNotRetained()`, `assertNothingRetained()`, `assertRecalled()`, `assertNotRecalled()` and `assertReflected()`. It follows the server where tests depend on it: a bank nobody wrote to answers 404, tag filters select the same memories, a retain with a document ID replaces the document, an async retain repeated with the same operation ID stores nothing new, and invalid operation IDs, timestamps, tags, metadata values and update modes are rejected with the server's status. `unavailable()` simulates an outage, `respondTo()` stubs other endpoints.
- `RetainMemory` job and `Memory::queue()`: store memories from a queue worker, retried with backoff while the Hindsight API is unavailable (six attempts over about 36 minutes by default; earlier attempts log a warning, only a failure on the last attempt is reported). Every attempt sends the operation ID the job got when it was dispatched, so a retry never stores a memory twice, and the dispatch time as the memory's timestamp. Errors a retry cannot fix fail the job at once and are reported.
- `queue` section in `config/hindsight.php`: connection, queue, tries, backoff and payload encryption.
- Bank aliases: `listBankAliases()`, `addBankAlias()`, `setPrimaryBankAlias()` and `removeBankAlias()`, on the client and the facade.
- `retryOperation()` to queue a failed or cancelled operation again.
- CI also runs the integration suite against Hindsight 1.2.0, and compares the fake with real 1.1.0 and 1.2.0 servers.

### Changed

- `Hindsight` is no longer `final`, so `HindsightFake` can stand in for it. Other subclasses are not supported.

## [0.1.0] - 2026-10-30

First release. Tested against Hindsight 1.1.0, Laravel 12 and 13, and Laravel AI SDK 1.0 to 1.3.

### Added

- Client for the Hindsight HTTP API: retain, retain in batches, recall, reflect, banks, bank configuration, memories, documents, directives, mental models and operations, plus `request()` for any other endpoint.
- `waitForOperation()` for async retains and mental model refreshes.
- Typed responses for retain, recall, reflect and operations; arrays with the API's keys for everything else.
- Exceptions for connection failures, 401/403, 404, 422, other API errors and non-JSON success responses.
- Warnings for parameters the server ignored (the `X-Ignored-Params` header) through any PSR-3 logger.
- Laravel service provider, config, facade and `hindsight:status` command.
- `Memory` middleware for Laravel AI SDK agents: recalls relevant memories before the first step, retains the exchange after the final step.
- `Recall`, `Retain` and `Reflect` tools for Laravel AI SDK agents.
- `withTimeout()` for a copy of the client with a shorter timeout. The middleware and tools use short timeouts by default, so a hung server does not hold up an agent for two minutes.
- `ConfigurationException` for a malformed URL, thrown when the client is created.

[0.9.0]: https://github.com/skybleak333/hindsight-php/releases/tag/v0.9.0
[0.8.0]: https://github.com/skybleak333/hindsight-php/releases/tag/v0.8.0
[0.7.0]: https://github.com/skybleak333/hindsight-php/releases/tag/v0.7.0
[0.6.0]: https://github.com/skybleak333/hindsight-php/releases/tag/v0.6.0
[0.5.0]: https://github.com/skybleak333/hindsight-php/releases/tag/v0.5.0
[0.4.0]: https://github.com/skybleak333/hindsight-php/releases/tag/v0.4.0
[0.3.0]: https://github.com/skybleak333/hindsight-php/releases/tag/v0.3.0
[0.2.0]: https://github.com/skybleak333/hindsight-php/releases/tag/v0.2.0
[0.1.0]: https://github.com/skybleak333/hindsight-php/releases/tag/v0.1.0
