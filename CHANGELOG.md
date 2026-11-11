# Changelog

All notable changes to this package are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package uses [Semantic Versioning](https://semver.org/).

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

[0.6.0]: https://github.com/skybleak333/hindsight-php/releases/tag/v0.6.0
[0.5.0]: https://github.com/skybleak333/hindsight-php/releases/tag/v0.5.0
[0.4.0]: https://github.com/skybleak333/hindsight-php/releases/tag/v0.4.0
[0.3.0]: https://github.com/skybleak333/hindsight-php/releases/tag/v0.3.0
[0.2.0]: https://github.com/skybleak333/hindsight-php/releases/tag/v0.2.0
[0.1.0]: https://github.com/skybleak333/hindsight-php/releases/tag/v0.1.0
