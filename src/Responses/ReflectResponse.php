<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Responses;

use Stringable;

final readonly class ReflectResponse implements Stringable
{
    /**
     * @param  string  $text  The answer as Markdown.
     * @param  array<string, mixed>|null  $basedOn  Memories, mental models and directives used, when facts were requested.
     * @param  array<string, mixed>|null  $structuredOutput  Parsed according to the response schema, when one was given.
     * @param  array<string, int>|null  $usage
     * @param  array<string, mixed>|null  $trace
     * @param  array<string, mixed>  $raw  The response exactly as the API returned it.
     */
    public function __construct(
        public string $text,
        public ?array $basedOn,
        public ?array $structuredOutput,
        public ?string $structuredOutputError,
        public ?array $usage,
        public ?array $trace,
        public array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            text: Fields::string($data, 'text') ?? '',
            basedOn: Fields::map($data, 'based_on'),
            structuredOutput: Fields::map($data, 'structured_output'),
            structuredOutputError: Fields::string($data, 'structured_output_error'),
            usage: Fields::counts($data, 'usage'),
            trace: Fields::map($data, 'trace'),
            raw: $data,
        );
    }

    public function __toString(): string
    {
        return $this->text;
    }
}
