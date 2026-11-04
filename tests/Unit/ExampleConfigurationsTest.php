<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngineWorker\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Nothing of this runs on a real cluster, it only keeps the settings aligned
 */
final class ExampleConfigurationsTest extends TestCase
{
    private const EXAMPLES = __DIR__ . '/../../examples/';

    /**
     * @return array<mixed>
     */
    private static function yaml(string $file): array
    {
        return Yaml::parseFile(self::EXAMPLES . 'kubernetes/' . $file);
    }

    /**
     * @return array<string, mixed>
     */
    private static function supervisor(): array
    {
        $configuration = parse_ini_file(self::EXAMPLES . 'supervisor/worker.conf', true, INI_SCANNER_RAW);
        self::assertIsArray($configuration);
        return $configuration['program:subscription-worker'];
    }

    #[Test]
    public function gracePeriodsAreTheSameEverywhere(): void
    {
        self::assertSame(
            (int) self::supervisor()['stopwaitsecs'],
            self::yaml('worker-deployment.yaml')['spec']['template']['spec']['terminationGracePeriodSeconds'],
        );
    }

    #[Test]
    public function signalsReachPhpDirectly(): void
    {
        self::assertSame('TERM', self::supervisor()['stopsignal']);
        self::assertSame(['php', '/app/examples/worker.php'], self::yaml('worker-deployment.yaml')['spec']['template']['spec']['containers'][0]['command']);
    }

    #[Test]
    public function theLivenessProbeChecksTheFileOfTheHeartbeat(): void
    {
        $probe = self::yaml('worker-deployment.yaml')['spec']['template']['spec']['containers'][0]['livenessProbe'];
        self::assertStringContainsString('/tmp/worker-heartbeat', implode(' ', $probe['exec']['command']));
        self::assertStringContainsString("touch('/tmp/worker-heartbeat')", (string) file_get_contents(self::EXAMPLES . 'worker.php'));
    }

    #[Test]
    public function kedaScalesTheDeploymentByTheNumberOfSubscriptionsThatAreBehind(): void
    {
        $scaledObject = self::yaml('keda-scaledobject.yaml');
        self::assertSame(self::yaml('worker-deployment.yaml')['metadata']['name'], $scaledObject['spec']['scaleTargetRef']['name']);
        self::assertSame('1', $scaledObject['spec']['triggers'][0]['metadata']['targetQueryValue']);
        self::assertSame(1, $scaledObject['spec']['minReplicaCount']);
    }

    #[Test]
    public function theReadmeDocumentsTheExactScalingQuery(): void
    {
        self::assertStringContainsString(
            self::yaml('keda-scaledobject.yaml')['spec']['triggers'][0]['metadata']['query'],
            (string) file_get_contents(__DIR__ . '/../../README.md'),
        );
    }
}
