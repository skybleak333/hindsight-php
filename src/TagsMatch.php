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
}
