<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Laravel\Ai\Tools;

use Fedoseev\Hindsight\Exceptions\NotFoundException;
use Fedoseev\Hindsight\TagsMatch;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

/**
 * Lets the agent read a page of long-term memory as markdown (Hindsight 1.2.1 and later).
 */
final class ReadPage extends MemoryTool
{
    public function description(): string
    {
        return 'Read a page of long-term memory as markdown. Get page ids from search_pages.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'page_id' => $schema->string()->description('The id of the page, as search_pages returns it.')->required(),
        ];
    }

    protected function defaultTimeout(): float
    {
        return 15.0;
    }

    protected function nothingStored(): string
    {
        return 'There is no such page.';
    }

    protected function defaultName(): string
    {
        return 'read_page';
    }

    protected function run(Request $request): string
    {
        try {
            $page = $this->client()->getPage($this->bankId, $request->string('page_id')->toString());
        } catch (NotFoundException $exception) {
            if ($exception->isMissingBank($this->bankId)) {
                throw $exception;
            }

            return 'There is no such page.';
        }

        // The id comes from the model, so a tool limited to some tags must not read a page outside them.
        if ($this->tags !== null && ! $this->visible(is_array($page['tags'] ?? null) ? $page['tags'] : [])) {
            return 'There is no such page.';
        }

        return is_string($page['markdown'] ?? null) ? $page['markdown'] : '';
    }

    /**
     * @param  array<mixed>  $pageTags
     */
    private function visible(array $pageTags): bool
    {
        $match = $this->tagsMatch instanceof TagsMatch ? $this->tagsMatch : TagsMatch::tryFrom($this->tagsMatch);

        // An unknown mode would be refused by the server, so nothing is shown.
        return $match !== null && $match->matches($this->tags ?? [], array_values(array_filter($pageTags, is_string(...))));
    }
}
