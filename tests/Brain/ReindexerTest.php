<?php

namespace App\Tests\Brain;

use App\Ai\ProviderKey;
use App\Brain\Reindexer;
use App\Tests\Support\FakeIndexer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\AI\Store\IndexerInterface;
use Symfony\AI\Store\StoreInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class ReindexerTest extends TestCase
{
    public function testDoesNothingWhenTheKeyIsMissing(): void
    {
        $store = $this->createMock(StoreInterface::class);
        $store->expects(self::never())->method('clear');
        $indexer = $this->createMock(IndexerInterface::class);
        $indexer->expects(self::never())->method('index');

        $this->reindexer($indexer, $store, new ProviderKey('cohere', '', 'hf'))->refresh();
    }

    public function testClearsTheStoreBeforeItIndexes(): void
    {
        $calls = [];
        $store = $this->createStub(StoreInterface::class);
        $store->method('clear')->willReturnCallback(static function () use (&$calls): void {
            $calls[] = 'clear';
        });
        $indexer = new FakeIndexer(static function () use (&$calls): void {
            $calls[] = 'index';
        });

        $this->reindexer($indexer, $store)->refresh();

        self::assertSame(['clear', 'index'], $calls);
    }

    public function testAFailureIsLoggedAndSwallowed(): void
    {
        $indexer = new FakeIndexer(static fn () => throw new \RuntimeException('provider down'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(self::stringContains('provider down'));

        $this->reindexer($indexer, $this->createStub(StoreInterface::class), logger: $logger)->refresh();
    }

    public function testTheLockIsReleasedAfterAFailure(): void
    {
        $locks = new LockFactory(new InMemoryStore());
        $indexer = new FakeIndexer(static fn () => throw new \RuntimeException('provider down'));

        $this->reindexer($indexer, $this->createStub(StoreInterface::class), locks: $locks)->refresh();

        self::assertTrue($locks->createLock('synapse-reindex', 300)->acquire(), 'The next run must get the lock.');
    }

    public function testTwoRunsInARowBothGoThrough(): void
    {
        $indexer = new FakeIndexer();
        $reindexer = $this->reindexer($indexer, $this->createStub(StoreInterface::class));

        $reindexer->refresh();
        $reindexer->refresh();

        self::assertSame(2, $indexer->runs);
    }

    public function testHuggingFaceNeedsItsOwnKey(): void
    {
        $indexer = $this->createMock(IndexerInterface::class);
        $indexer->expects(self::never())->method('index');

        $this->reindexer($indexer, $this->createStub(StoreInterface::class), new ProviderKey('huggingface', 'cohere-key', ''))->refresh();
    }

    private function reindexer(IndexerInterface $indexer, StoreInterface $store, ?ProviderKey $key = null, ?LoggerInterface $logger = null, ?LockFactory $locks = null): Reindexer
    {
        return new Reindexer(
            $indexer,
            $store,
            $key ?? new ProviderKey('cohere', 'key', ''),
            $locks ?? new LockFactory(new InMemoryStore()),
            $logger ?? $this->createStub(LoggerInterface::class),
        );
    }
}
