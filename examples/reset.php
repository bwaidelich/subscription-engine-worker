<?php

declare(strict_types=1);

use Wwwision\SubscriptionEngine\Engine\SubscriptionEngineCriteria;

/**
 * Rebuilds a projection from the first event, workers can keep running: php examples/reset.php some-projection
 */

['engine' => $engine] = require __DIR__ . '/bootstrap.php';

$subscriptionId = $argv[1] ?? null;
if (!is_string($subscriptionId) || $subscriptionId === '') {
    fwrite(STDERR, "Usage: php reset.php <subscription-id>\n");
    exit(2);
}
$result = $engine->reset(SubscriptionEngineCriteria::create([$subscriptionId]));
exit($result->errors === null ? 0 : 1);
