<?php

namespace App\Tests\Support;

use Symfony\AI\Store\IndexerInterface;

/**
 * Takes no argument, like the configured indexer the bundle builds. The interface asks for one, but the
 * Reindexer calls index() bare, so a PHPUnit stub generated from the interface would fail for the wrong reason.
 */
final class FakeIndexer implements IndexerInterface
{
    public int $runs = 0;

    /**
     * @param \Closure(): void|null $onIndex
     */
    public function __construct(private readonly ?\Closure $onIndex = null)
    {
    }

    public function index(string|iterable|object|null $input = null, array $options = []): void
    {
        ++$this->runs;
        if (null !== $this->onIndex) {
            ($this->onIndex)();
        }
    }
}
