<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Tests\Unit;

use Fedoseev\Hindsight\Exceptions\NotFoundException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NotFoundExceptionTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function bodies(): iterable
    {
        yield 'the server names the bank' => [['detail' => "Bank 'user-1' not found"], true];
        yield 'another bank with the same prefix' => [['detail' => "Bank 'user-12' not found"], false];
        yield 'another bank that contains the id' => [['detail' => "Bank 'old-user-1' not found"], false];
        yield 'a missing memory, not a bank' => [['detail' => 'Memory not found'], false];
        yield 'a proxy page' => ['<html><title>404 Not Found</title></html>', false];
    }

    #[DataProvider('bodies')]
    public function test_it_tells_a_missing_bank_from_other_404s(mixed $body, bool $missingBank): void
    {
        $exception = NotFoundException::fromResponse(404, $body);

        $this->assertInstanceOf(NotFoundException::class, $exception);
        $this->assertSame($missingBank, $exception->isMissingBank('user-1'));
    }
}
