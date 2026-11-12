<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Neuron;

use Closure;
use Fedoseev\Hindsight\Hindsight;
use Fedoseev\Hindsight\Neuron\Tools\Recall;
use Fedoseev\Hindsight\Neuron\Tools\Reflect;
use Fedoseev\Hindsight\Neuron\Tools\Retain;
use NeuronAI\Tools\Toolkits\AbstractToolkit;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Long-term memory for a Neuron AI agent: recall, retain and reflect on one Hindsight bank,
 * with guidelines that tell the model when to use them.
 */
final class HindsightToolkit extends AbstractToolkit
{
    /**
     * @param  string|Closure(): string  $bank  the bank ID, or a closure that returns it for the current user
     * @param  list<string>|null  $tags  tags given to retained memories and required on recalled ones
     */
    public function __construct(
        private readonly Hindsight $client,
        private readonly string|Closure $bank,
        private readonly ?array $tags = null,
        private readonly LoggerInterface $logger = new NullLogger,
    ) {}

    public function guidelines(): string
    {
        return 'You have a long-term memory that lasts across conversations. Before answering a question that may '
            .'depend on what the user told you earlier, call recall_memory. When the user shares a lasting fact, '
            .'preference or decision, store it with retain_memory. Do not store small talk.';
    }

    public function provide(): array
    {
        return [
            new Recall($this->client, $this->bank, $this->tags, logger: $this->logger),
            new Retain($this->client, $this->bank, $this->tags, logger: $this->logger),
            new Reflect($this->client, $this->bank, $this->tags, logger: $this->logger),
        ];
    }
}
