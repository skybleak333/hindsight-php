<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Laravel\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

/**
 * Lets the agent find the pages that summarise long-term memory (Hindsight 1.2.1 and later).
 */
final class SearchPages extends MemoryTool
{
    private int $limit = 5;

    /**
     * How many pages to return, at most 50.
     */
    public function limit(int $limit): self
    {
        $this->limit = $limit;

        return $this;
    }

    public function description(): string
    {
        return 'Search the pages that summarise long-term memory, such as a profile of a customer or of a project. '
            .'Returns page ids to read with read_page.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('What to look for, in a few words.')->required(),
        ];
    }

    protected function defaultTimeout(): float
    {
        return 15.0;
    }

    protected function nothingStored(): string
    {
        return 'No pages found.';
    }

    protected function defaultName(): string
    {
        return 'search_pages';
    }

    protected function run(Request $request): string
    {
        $results = $this->client()->searchPages(
            $this->bankId,
            $request->string('query')->toString(),
            limit: $this->limit,
            tags: $this->tags,
            tagsMatch: $this->tagsMatch,
        )['results'] ?? [];

        $lines = [];

        foreach (is_array($results) ? $results : [] as $page) {
            if (is_array($page) && is_string($page['id'] ?? null)) {
                $name = is_string($page['name'] ?? null) ? $page['name'] : $page['id'];
                $snippet = is_string($page['snippet'] ?? null) ? $page['snippet'] : '';
                $lines[] = "- {$name} (id: {$page['id']}): {$snippet}";
            }
        }

        return $lines === [] ? 'No pages found.' : "Pages, best match first:\n".implode("\n", $lines);
    }
}
