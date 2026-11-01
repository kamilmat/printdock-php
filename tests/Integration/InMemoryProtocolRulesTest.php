<?php

declare(strict_types=1);

namespace PrintDock\Server\Tests\Integration;

use PrintDock\Server\Tests\Support\Stack;

final class InMemoryProtocolRulesTest extends ProtocolRulesTestCase
{
    protected function createStack(): Stack
    {
        return Stack::inMemory();
    }
}
