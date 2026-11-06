<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Tests\Laravel;

use Fedoseev\Hindsight\Exceptions\AuthenticationException;
use Fedoseev\Hindsight\Exceptions\ConfigurationException;
use Fedoseev\Hindsight\Exceptions\ConnectionException;
use Fedoseev\Hindsight\Hindsight;
use Fedoseev\Hindsight\Laravel\Ai\Memory;
use Fedoseev\Hindsight\Laravel\Facades\Hindsight as HindsightFacade;
use Fedoseev\Hindsight\Laravel\Jobs\RetainMemory;
use Fedoseev\Hindsight\Testing\FakeServer;
use Fedoseev\Hindsight\Tests\Laravel\Fixtures\SupportAgent;
use Fedoseev\Hindsight\Tests\Support\ContractCheckingClient;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use LogicException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Queued retains with a real worker (`queue:work`) on the database queue driver.
 */
final class QueuedRetainTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('queue.default', 'database');
        $app['config']->set('queue.failed.driver', 'database-uuids');
        $app['config']->set('queue.failed.database', 'testing');
        // Encrypted payloads need an application key, as every Laravel application has.
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadLaravelMigrations();
    }

    public function test_a_retain_that_fails_while_hindsight_is_down_is_stored_once_it_is_back(): void
    {
        // Frozen, so a second that ticks over between the release and the assertion cannot change the delay.
        $this->freezeTime();
        $fake = HindsightFacade::fake()->unavailable(status: 503);
        RetainMemory::dispatch('user-1', 'Prefers email over phone.', tags: ['user:1']);

        $this->work();

        $fake->assertNothingRetained();
        $job = DB::table('jobs')->sole();
        $this->assertSame(1, (int) $job->attempts);
        $this->assertSame(10, $job->available_at - Carbon::now()->getTimestamp(), 'Released with the first backoff step.');

        $fake->available();
        $this->travel(11)->seconds();
        $this->work();

        $fake->assertRetained('user-1', fn (string $content, array $item): bool => $content === 'Prefers email over phone.' && $item['tags'] === ['user:1']);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_a_retry_after_a_lost_answer_does_not_store_the_memory_twice(): void
    {
        // The server stores the memory, then the connection drops before the answer arrives.
        $server = new FakeServer;
        $client = new AnswerLosingClient(new ContractCheckingClient($server), losses: 1);
        $this->app->instance(Hindsight::class, new Hindsight('http://hindsight.test', httpClient: $client));
        Exceptions::fake();

        RetainMemory::dispatch('user-1', 'Lives in Rotterdam.');

        $this->work();
        $this->assertSame(1, (int) DB::table('jobs')->sole()->attempts, 'The worker saw a failure and released the job.');
        Exceptions::assertNothingReported();

        $this->travel(11)->seconds();
        $this->work();

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertCount(2, $client->operationIds);
        $this->assertSame($client->operationIds[0], $client->operationIds[1], 'Both attempts sent the same operation ID.');
        $this->assertCount(1, $server->retained(), 'The server stored the memory once.');
    }

    public function test_an_error_that_a_retry_cannot_fix_fails_the_job_at_once_and_is_reported(): void
    {
        $fake = HindsightFacade::fake()->unavailable(status: 401);
        Exceptions::fake();

        RetainMemory::dispatch('user-1', 'Prefers email over phone.');
        $this->work();

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(1, DB::table('failed_jobs')->count());
        Exceptions::assertReported(AuthenticationException::class);
        $fake->assertNothingRetained();
    }

    public function test_the_default_retry_policy_spans_about_half_an_hour(): void
    {
        $job = new RetainMemory('b', 'x');

        $this->assertSame(6, $job->tries);
        // Five waits between six attempts, as Worker::calculateBackoff() repeats the last step: 2,170 seconds.
        $this->assertSame([10, 60, 300, 900], $job->backoff);
    }

    public function test_the_job_carries_its_retry_policy_and_the_time_it_was_dispatched(): void
    {
        config(['hindsight.queue.tries' => 3, 'hindsight.queue.backoff' => [5, 50]]);
        Carbon::setTestNow('2026-11-06 09:30:00');

        RetainMemory::dispatch('user-1', 'Prefers email over phone.');

        $payload = json_decode(DB::table('jobs')->sole()->payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(3, $payload['maxTries']);
        $this->assertSame('5,50', $payload['backoff']);
        $this->assertNull($payload['timeout'], 'The job sets no time limit of its own; the worker\'s applies.');

        // Processed an hour later, the memory still carries the time it was dispatched.
        $fake = HindsightFacade::fake();
        $this->travel(1)->hours();
        $this->work();

        $fake->assertRetained('user-1', fn (string $content, array $item): bool => $item['timestamp'] === '2026-11-06T09:30:00+00:00');
    }

    public function test_connection_and_queue_come_from_the_config_or_the_call(): void
    {
        config(['hindsight.queue.connection' => 'redis', 'hindsight.queue.queue' => 'memory']);

        $job = new RetainMemory('b', 'x');
        $this->assertSame('redis', $job->connection);
        $this->assertSame('memory', $job->queue);

        Queue::fake();
        SupportAgent::fake(['Hi!']);
        HindsightFacade::fake(['b' => []]);

        (new SupportAgent([Memory::bank('b')->queue('sqs', 'agent-memory')]))->prompt('Hello');

        Queue::assertPushedOn('agent-memory', RetainMemory::class, fn (RetainMemory $job): bool => $job->connection === 'sqs');
    }

    public function test_the_middleware_queues_the_exchange_instead_of_storing_it_during_the_run(): void
    {
        Queue::fake();
        SupportAgent::fake(['Your plan renews on 1 December.']);
        $fake = HindsightFacade::fake(['user-1' => ['Alice is on the Pro plan']]);

        $memory = Memory::bank('user-1')->tags(['user:1'])->document('conversation-7')->context('Support chat')->queue();
        (new SupportAgent([$memory]))->prompt('When does my plan renew?');

        $fake->assertRecalled('user-1');
        $fake->assertNothingRetained();
        Queue::assertPushed(RetainMemory::class, fn (RetainMemory $job): bool => $job->bankId === 'user-1'
            && $job->content === "USER: When does my plan renew?\nASSISTANT: Your plan renews on 1 December."
            && $job->memoryTags === ['user:1']
            && $job->documentId === 'conversation-7'
            && $job->updateMode === 'append'
            && $job->context === 'Support chat'
            && $job->requestTimeout === Memory::DEFAULT_TIMEOUT);
    }

    public function test_a_conversation_during_an_outage_is_remembered_after_it(): void
    {
        SupportAgent::fake(['Noted: email it is.', 'You prefer email.']);
        Exceptions::fake();
        $fake = HindsightFacade::fake()->unavailable();

        // Hindsight is down: recall fails and is reported, the agent answers anyway, the exchange waits in the queue.
        $answer = (new SupportAgent([Memory::bank('user-1')->queue()]))->prompt('Please contact me by email.');
        $this->assertSame('Noted: email it is.', $answer->text);
        Exceptions::assertReported(ConnectionException::class);
        $this->work();
        $this->assertSame(1, DB::table('jobs')->count());

        // It is back: the worker stores the exchange, and the next conversation recalls it.
        $fake->available();
        $this->travel(11)->seconds();
        $this->work();

        $fake->assertRetained('user-1', fn (string $content): bool => str_contains($content, 'contact me by email'));
        $this->assertSame(["USER: Please contact me by email.\nASSISTANT: Noted: email it is."], $fake->recall('user-1', 'contact')->texts());
    }

    public function test_only_a_failure_on_the_last_attempt_is_reported(): void
    {
        config(['hindsight.queue.tries' => 3]);
        $this->freezeTime();
        Exceptions::fake();
        Log::spy();
        $fake = HindsightFacade::fake()->unavailable();

        RetainMemory::dispatch('user-1', 'Prefers email over phone.');

        $this->work();
        $this->travel(11)->seconds();
        $this->work();
        Exceptions::assertNothingReported();
        $this->assertSame(60, DB::table('jobs')->sole()->available_at - Carbon::now()->getTimestamp(), 'Second step of the backoff.');

        $this->travel(61)->seconds();
        $this->work();

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(ConnectionException::class);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(1, DB::table('failed_jobs')->count());
        $fake->assertNothingRetained();
        Log::shouldHaveReceived('warning')->twice()->withArgs(fn (string $message): bool => str_contains($message, 'Hindsight retain for bank [user-1] failed on attempt'));
    }

    public function test_408_429_and_5xx_are_temporary(): void
    {
        $fake = HindsightFacade::fake();

        foreach ([408, 429, 500, 503] as $status) {
            $fake->unavailable(times: 1, status: $status);
            RetainMemory::dispatch('user-1', "After a {$status}.");
            $this->work();
            $this->assertSame(1, DB::table('jobs')->count(), "{$status} released the job.");

            $this->travel(11)->seconds();
            $this->work();
            $this->assertSame(0, DB::table('jobs')->count(), "Stored after the {$status}.");
        }

        $fake->assertRetained('user-1', 4);
    }

    public function test_a_malformed_url_fails_the_job_at_once(): void
    {
        config(['hindsight.url' => 'localhost:8888']);
        $this->app->forgetInstance(Hindsight::class);
        Exceptions::fake();

        RetainMemory::dispatch('user-1', 'Prefers email over phone.');
        $this->work();

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(1, DB::table('failed_jobs')->count());
        Exceptions::assertReported(ConfigurationException::class);
    }

    public function test_operation_ids_stay_unique_when_laravel_uuids_are_frozen(): void
    {
        Str::freezeUuids();

        try {
            $first = new RetainMemory('user-1', 'One.');
            $second = new RetainMemory('user-1', 'Two.');
        } finally {
            Str::createUuidsNormally();
        }

        $this->assertNotSame($first->operationId, $second->operationId);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $first->operationId);
    }

    public function test_the_payload_can_be_encrypted(): void
    {
        RetainMemory::dispatch('user-1', 'Plain text in the queue.');
        config(['hindsight.queue.encrypt' => true]);
        RetainMemory::dispatch('user-1', 'Secret in the queue.');

        [$plain, $encrypted] = DB::table('jobs')->orderBy('id')->pluck('payload')->all();
        $this->assertStringContainsString('Plain text in the queue.', $plain);
        $this->assertStringNotContainsString('Secret in the queue.', $encrypted);

        $fake = HindsightFacade::fake();
        $this->work();
        $this->work();
        $fake->assertRetained('user-1', fn (string $content): bool => $content === 'Secret in the queue.');
    }

    public function test_on_the_sync_driver_errors_reach_the_run(): void
    {
        config(['queue.default' => 'sync']);
        SupportAgent::fake(['Hi!', 'Hi again!']);
        Exceptions::fake();
        $fake = HindsightFacade::fake(['b' => []]);

        // A 401 is thrown when the agent asks for that, as without queue().
        $fake->unavailable(times: 1, status: 401);
        try {
            (new SupportAgent([Memory::bank('b')->withoutRecall()->throwOnError()->queue()]))->prompt('Hello');
            $this->fail('Expected the 401.');
        } catch (AuthenticationException) {
        }

        // There are no retries on sync: a 503 is reported once and the exchange is not stored.
        $fake->unavailable(times: 1, status: 503);
        (new SupportAgent([Memory::bank('b')->withoutRecall()->queue()]))->prompt('Hello again');

        Exceptions::assertReportedCount(1);
        $fake->assertNothingRetained();
    }

    public function test_queue_cannot_be_combined_with_using(): void
    {
        $client = new Hindsight('http://tenant.test');

        foreach ([fn () => Memory::bank('b')->using($client)->queue(), fn () => Memory::bank('b')->queue()->using($client)] as $call) {
            try {
                $call();
                $this->fail('Expected a LogicException.');
            } catch (LogicException $exception) {
                $this->assertStringContainsString('resolves the client from the container in the worker', $exception->getMessage());
            }
        }
    }

    public function test_a_queued_retain_is_always_async(): void
    {
        $this->assertThrowsLogic(fn () => Memory::bank('b')->queue()->retainSynchronously());
        $this->assertThrowsLogic(fn () => Memory::bank('b')->retainSynchronously()->queue());
    }

    private function work(): void
    {
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->assertSuccessful();
    }

    private function assertThrowsLogic(callable $call): void
    {
        try {
            $call();
        } catch (LogicException $exception) {
            $this->assertSame('A queued retain is always async: call either queue() or retainSynchronously().', $exception->getMessage());

            return;
        }

        $this->fail('Expected a LogicException.');
    }
}

/**
 * Passes a request on, then throws away the answer, as when a connection drops after the server has done the work.
 */
final class AnswerLosingClient implements ClientInterface
{
    /** @var list<string|null> The operation_id each retain request carried. */
    public array $operationIds = [];

    public function __construct(private readonly ClientInterface $client, private int $losses) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true);
        $this->operationIds[] = is_array($body) && is_string($body['operation_id'] ?? null) ? $body['operation_id'] : null;

        $response = $this->client->sendRequest($request);

        if ($this->losses > 0) {
            $this->losses--;

            throw new ConnectException('Connection reset by peer', $request);
        }

        return $response;
    }
}
