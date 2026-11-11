<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight;

/**
 * How a tag filter matches memories.
 */
enum TagsMatch: string
{
    /** At least one tag matches; untagged memories are included. The API default. */
    case Any = 'any';

    /** Every tag matches; untagged memories are included. */
    case All = 'all';

    /** At least one tag matches; untagged memories are excluded. */
    case AnyStrict = 'any_strict';

    /** Every tag matches; untagged memories are excluded. */
    case AllStrict = 'all_strict';

    /** The memory's tags equal the filter exactly; untagged memories are excluded. */
    case Exact = 'exact';

    /**
     * Whether something with $tags passes a filter on $filter, by the server's rules: an empty filter lets
     * everything through, except with "exact", where it means untagged only.
     *
     * @param  list<string>  $filter
     * @param  list<string>  $tags
     */
    public function matches(array $filter, array $tags): bool
    {
        if ($this === self::Exact) {
            // Set equality, as Postgres compares the arrays: order and repeats do not matter.
            return self::normalized($filter) === self::normalized($tags);
        }

        if ($filter === []) {
            return true;
        }

        if ($tags === []) {
            return $this === self::Any || $this === self::All;
        }

        return $this === self::Any || $this === self::AnyStrict
            ? array_intersect($filter, $tags) !== []
            : array_diff($filter, $tags) === [];
    }

    /**
     * @param  list<string>  $tags
     * @return list<string>
     */
    private static function normalized(array $tags): array
    {
        $tags = array_values(array_unique($tags));
        sort($tags);

        return $tags;
    }
}
