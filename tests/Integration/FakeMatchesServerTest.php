<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Tests\Integration;

use Closure;
use Fedoseev\Hindsight\Exceptions\ApiException;
use Fedoseev\Hindsight\Exceptions\NotFoundException;
use Fedoseev\Hindsight\Exceptions\ValidationException;
use Fedoseev\Hindsight\Hindsight;
use Fedoseev\Hindsight\TagsMatch;
use Fedoseev\Hindsight\Testing\HindsightFake;
use Fedoseev\Hindsight\Tests\Unit\HindsightFakeTest;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;

/**
 * The same calls against HindsightFake and a real server give the same answers, where the fake claims to
 * follow the server.
 */
final class FakeMatchesServerTest extends TestCase
{
    use UsesRealHindsight;

    private const PREFIX = 'Garden note: ';

    protected function setUp(): void
    {
        parent::setUp();

        $this->connectToHindsight();
    }

    protected function tearDown(): void
    {
        $this->deleteBanks();

        parent::tearDown();
    }

    /**
     * @param  list<string>|null  $tags
     * @param  list<string>  $expected
     */
    #[DataProviderExternal(HindsightFakeTest::class, 'tagFilters')]
    public function test_tag_filters(?array $tags, TagsMatch $match, array $expected): void
    {
        $results = [];

        foreach (['server' => $this->hindsight, 'fake' => new HindsightFake] as $name => $client) {
            $bank = $this->newBank();
            $client->retainBatch($bank, [
                ['content' => self::PREFIX.'untagged'],
                ['content' => self::PREFIX.'tagged a', 'tags' => ['a']],
                ['content' => self::PREFIX.'tagged b', 'tags' => ['b']],
                ['content' => self::PREFIX.'tagged a and b', 'tags' => ['a', 'b']],
            ]);

            $results[$name] = $this->labels($client, $bank, $tags, $match);
        }

        sort($expected);
        $this->assertSame($expected, $results['server'], 'The server');
        $this->assertSame($expected, $results['fake'], 'The fake');
    }

    public function test_a_retain_with_the_same_document_id_replaces_the_document(): void
    {
        foreach (['server' => $this->hindsight, 'fake' => new HindsightFake] as $name => $client) {
            $bank = $this->newBank();
            $client->retain($bank, self::PREFIX.'first version', documentId: 'doc');
            $client->retain($bank, self::PREFIX.'second version', documentId: 'doc');

            $this->assertSame(['second version'], $this->labels($client, $bank, null, TagsMatch::Any), $name);
        }
    }

    /**
     * @param  Closure(Hindsight, string): mixed  $call
     */
    #[DataProviderExternal(HindsightFakeTest::class, 'rejectedRetains')]
    public function test_rejected_retains(Closure $call, int $status, ?string $field): void
    {
        $bank = $this->newBank();
        $answers = [];

        foreach (['server' => $this->hindsight, 'fake' => new HindsightFake] as $name => $client) {
            try {
                $call($client, $bank);
                $this->fail("{$name}: expected the retain to be rejected.");
            } catch (ApiException $exception) {
                $answers[$name] = [$exception->statusCode, $exception instanceof ValidationException ? array_keys($exception->errors()) : null];
            }
        }

        $this->assertSame($status, $answers['server'][0], 'The server');
        $this->assertSame($answers['server'], $answers['fake']);
    }

    public function test_a_bank_nobody_wrote_to(): void
    {
        $bank = $this->newBank();
        $details = [];

        foreach (['server' => $this->hindsight, 'fake' => new HindsightFake] as $name => $client) {
            try {
                $client->recall($bank, 'anything');
                $this->fail("{$name}: expected a 404.");
            } catch (NotFoundException $exception) {
                $details[$name] = $exception->body;
            }
        }

        $this->assertSame($details['server'], $details['fake']);
    }

    /**
     * @param  list<string>|null  $tags
     * @return list<string>
     */
    private function labels(Hindsight $client, string $bank, ?array $tags, TagsMatch $match): array
    {
        $labels = array_map(
            static fn (string $text): string => str_starts_with($text, self::PREFIX) ? substr($text, strlen(self::PREFIX)) : $text,
            $client->recall($bank, 'Garden note', maxTokens: 4096, tags: $tags, tagsMatch: $match)->texts(),
        );

        sort($labels);

        return $labels;
    }
}
