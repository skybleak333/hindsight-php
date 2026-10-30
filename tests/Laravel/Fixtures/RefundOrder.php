<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Tests\Laravel\Fixtures;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * A tool that always waits for a human to approve it.
 */
final class RefundOrder implements Approvable, Tool
{
    use InteractsWithApprovals;

    public function __construct()
    {
        $this->requireApproval('Refunds need a human.');
    }

    public function name(): string
    {
        return 'refund_order';
    }

    public function description(): string
    {
        return 'Refund an order.';
    }

    public function handle(Request $request): string
    {
        return 'Refunded.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
