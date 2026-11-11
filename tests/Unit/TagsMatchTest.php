<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Tests\Unit;

use Fedoseev\Hindsight\TagsMatch;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TagsMatchTest extends TestCase
{
    /**
     * @return iterable<string, array{TagsMatch, list<string>, list<string>, bool}>
     */
    public static function cases(): iterable
    {
        yield 'no filter lets everything through' => [TagsMatch::AnyStrict, [], ['a'], true];
        yield 'no filter with exact means untagged only' => [TagsMatch::Exact, [], ['a'], false];
        yield 'exact with no filter and no tags' => [TagsMatch::Exact, [], [], true];
        yield 'any lets untagged through' => [TagsMatch::Any, ['a'], [], true];
        yield 'any_strict does not' => [TagsMatch::AnyStrict, ['a'], [], false];
        yield 'any needs a common tag' => [TagsMatch::Any, ['a'], ['b'], false];
        yield 'all needs every tag' => [TagsMatch::All, ['a', 'b'], ['a'], false];
        yield 'all_strict with more tags' => [TagsMatch::AllStrict, ['a', 'b'], ['b', 'c', 'a'], true];
        yield 'exact ignores order and repeats' => [TagsMatch::Exact, ['a', 'b'], ['b', 'a', 'a'], true];
        yield 'exact allows no other tag' => [TagsMatch::Exact, ['a'], ['a', 'b'], false];
    }

    /**
     * @param  list<string>  $filter
     * @param  list<string>  $tags
     */
    #[DataProvider('cases')]
    public function test_it_follows_the_server_rules(TagsMatch $match, array $filter, array $tags, bool $passes): void
    {
        $this->assertSame($passes, $match->matches($filter, $tags));
    }
}
