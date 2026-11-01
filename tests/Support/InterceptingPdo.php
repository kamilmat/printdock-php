<?php

declare(strict_types=1);

namespace PrintDock\Server\Tests\Support;

/**
 * A PDO connection that records every statement it prepares or executes and can run a hook before each one,
 * to see which SQL an operation sends or to slip in what a concurrent process would do.
 */
final class InterceptingPdo extends \PDO
{
    /** @var list<string> */
    public array $statements = [];

    /** @var (\Closure(string): void)|null */
    public ?\Closure $before = null;

    public function exec(string $statement): int|false
    {
        $this->intercept($statement);

        return parent::exec($statement);
    }

    /**
     * @param array<mixed> $options
     */
    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        $this->intercept($query);

        return parent::prepare($query, $options);
    }

    private function intercept(string $sql): void
    {
        $this->statements[] = $sql;
        if (null !== $this->before) {
            ($this->before)($sql);
        }
    }
}
