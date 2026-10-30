<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight;

/**
 * How much work recall and reflect may spend on retrieval.
 */
enum Budget: string
{
    case Low = 'low';
    case Mid = 'mid';
    case High = 'high';
}
