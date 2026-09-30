<?php

declare(strict_types=1);

use Reactor\Dispatcher;

require_once __DIR__ . '/Fixtures/Fixtures.php';

/**
 * Fresh dispatcher for every test.
 */
function makeDispatcher(): Dispatcher
{
    return new Dispatcher();
}
