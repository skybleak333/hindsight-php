<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Tests\Laravel\Fixtures;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

final class LookupInvoice implements Tool
{
    public function name(): string
    {
        return 'lookup_invoice';
    }

    public function description(): string
    {
        return 'Look up an invoice.';
    }

    public function handle(Request $request): string
    {
        return 'Invoice 7: paid on 2026-10-01.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
