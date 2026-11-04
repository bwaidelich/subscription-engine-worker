<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngineWorker\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Wwwision\SubscriptionEngineWorker\WorkerOptions;

final class WorkerOptionsTest extends TestCase
{
    /**
     * @return iterable<array{array<string, int|float>}>
     */
    public static function invalidOptions(): iterable
    {
        yield 'limit' => [['limit' => 0]];
        yield 'maxIdleWait' => [['maxIdleWait' => 0.0]];
        yield 'maxRuntime' => [['maxRuntime' => 0.0]];
        yield 'maxIterations' => [['maxIterations' => 0]];
        yield 'memoryLimit' => [['memoryLimit' => 0]];
    }

    /**
     * @param array<string, int|float> $options
     */
    #[Test]
    #[DataProvider('invalidOptions')]
    public function invalidValuesAreRejected(array $options): void
    {
        $this->expectException(InvalidArgumentException::class);
        WorkerOptions::create(...$options);
    }
}
