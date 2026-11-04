<?php

declare(strict_types=1);

use Wwwision\DCBEventStore\Event\Event;
use Wwwision\DCBEventStore\Event\Events;

/**
 * Stores a test event, a running worker passes it to the subscribers
 */

['eventStore' => $eventStore] = require __DIR__ . '/bootstrap.php';

$eventStore->append(Events::fromArray([Event::create('SomethingHappened', '{}')]));
