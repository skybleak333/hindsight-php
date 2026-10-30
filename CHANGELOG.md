# Changelog

All notable changes to this package are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

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
