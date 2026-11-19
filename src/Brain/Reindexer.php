<?php

namespace App\Brain;

use App\Ai\ProviderKey;
use Psr\Log\LoggerInterface;
use Symfony\AI\Store\IndexerInterface;
use Symfony\AI\Store\StoreInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Lock\LockFactory;

/**
 * Builds the search index again after a note changed, so a new note or a new status can be found right away.
 * It is the same as ai:store:clear and ai:store:index. Every note is embedded again, which is fine for a few hundred
 * notes and the reason this is not done on every read. Two runs never overlap: the second waits for the first.
 */
final class Reindexer
{
    public function __construct(
        #[Target('brain')]
        private readonly IndexerInterface $indexer,
        #[Target('brain')]
        private readonly StoreInterface $store,
        private readonly ProviderKey $keys,
        private readonly LockFactory $locks,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** A failure is logged and does not undo the change to the note. The index is then rebuilt by the next change or by hand. */
    public function refresh(): void
    {
        if (null !== $this->keys->missing()) {
            return;
        }

        $lock = $this->locks->createLock('synapse-reindex', 300);
        $lock->acquire(true);
        try {
            $this->store->clear();
            $this->indexer->index();
        } catch (\Throwable $e) {
            $this->logger->error('Could not rebuild the index: '.$e->getMessage(), ['exception' => $e]);
        } finally {
            $lock->release();
        }
    }
}
