<?php

declare(strict_types=1);

/**
 * A deploy step, not part of the worker: Concurrent setups can fail while they discover new subscriptions
 */

['eventStore' => $eventStore, 'engine' => $engine] = require __DIR__ . '/bootstrap.php';

$eventStore->setup();
$result = $engine->setup();
exit($result->errors === null ? 0 : 1);
