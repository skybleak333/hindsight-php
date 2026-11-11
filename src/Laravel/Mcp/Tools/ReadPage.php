<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Laravel\Mcp\Tools;

use Fedoseev\Hindsight\Exceptions\NotFoundException;
use Fedoseev\Hindsight\TagsMatch;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Lets the client read a page of long-term memory as markdown (Hindsight 1.2.1 and later).
 */
#[IsReadOnly]
#[IsOpenWorld(false)]
final class ReadPage extends MemoryTool
{
    protected string $title = 'Read a memory page';

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

    protected function run(Request $request, string $bankId): string
    {
        $request->validate(['page_id' => ['required', 'string']]);

        try {
            $page = $this->client()->getPage($bankId, $request->string('page_id')->toString());
        } catch (NotFoundException $exception) {
            if ($exception->isMissingBank($bankId)) {
                throw $exception;
            }

            return 'There is no such page.';
        }

        // The id comes from the client, so a tool limited to some tags must not read a page outside them.
        if ($this->tags !== null && ! $this->visible(is_array($page['tags'] ?? null) ? $page['tags'] : [])) {
            return 'There is no such page.';
        }

        return is_string($page['markdown'] ?? null) ? $page['markdown'] : '';
    }

    /**
     * The rules of Hindsight's tag filters: "any" and "all" also allow untagged pages, the strict modes and "exact" do not.
     *
     * @param  array<mixed>  $pageTags
     */
    private function visible(array $pageTags): bool
    {
        $match = $this->tagsMatch instanceof TagsMatch ? $this->tagsMatch : TagsMatch::tryFrom($this->tagsMatch);

        if ($match === null) {
            return false;
        }

        $pageTags = array_values(array_unique(array_filter($pageTags, is_string(...))));
        $tags = array_values(array_unique($this->tags ?? []));
        $common = array_intersect($tags, $pageTags);
        $hasAll = count($common) === count($tags);

        sort($pageTags);
        sort($tags);

        return match ($match) {
            TagsMatch::Any => $pageTags === [] || $common !== [],
            TagsMatch::AnyStrict => $common !== [],
            TagsMatch::All => $pageTags === [] || $hasAll,
            TagsMatch::AllStrict => $pageTags !== [] && $hasAll,
            TagsMatch::Exact => $pageTags === $tags,
        };
    }
}
