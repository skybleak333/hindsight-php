# Changelog

All notable changes to this package are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

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

[0.1.0]: https://github.com/skybleak333/hindsight-php/releases/tag/v0.1.0
