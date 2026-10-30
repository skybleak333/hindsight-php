# Contributing

Thanks for helping. Bug reports with a way to reproduce them are the most useful thing you can send.

## Running the tests

```bash
composer install
composer test          # unit and Laravel tests, no server needed
composer analyse       # PHPStan, level 9
```

Every request a unit test sends is checked against Hindsight's OpenAPI document in `tests/Fixtures`. If you add a method, add a test for it, and the check comes for free.

## Integration tests

They need a Hindsight server on `127.0.0.1:8888`. The cheapest one runs without an LLM:

```bash
pip install "hindsight-api-slim[local-onnx,embedded-db]==1.1.0"
HINDSIGHT_API_LLM_PROVIDER=none HINDSIGHT_API_EMBEDDINGS_PROVIDER=onnx HINDSIGHT_API_RERANKER_PROVIDER=rrf hindsight-api
composer test:integration
```

The tests create banks named `hindsight-php-test-*` and delete them afterwards. They refuse to run against any host other than localhost unless you set `HINDSIGHT_TEST_ALLOW_REMOTE=1`. Do not point them at a server with memories you care about.

## Pull requests

- Keep the public API in line with the official Python and TypeScript clients where they have the same feature.
- Run `composer test` and `composer analyse` before you push.
