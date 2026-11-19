<?php

namespace App\EventListener;

use App\Ai\ProviderKey;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * The commands that call a model stop with a clear message when the key is empty, before a single request goes out.
 */
#[AsEventListener(event: ConsoleEvents::COMMAND)]
final class RequireProviderKey
{
    private const NEEDS_PROVIDER = ['app:ask', 'app:capture', 'app:federate', 'ai:store:index'];

    public function __construct(private readonly ProviderKey $keys)
    {
    }

    public function __invoke(ConsoleCommandEvent $event): void
    {
        $name = $event->getCommand()?->getName();
        if (!\in_array($name, self::NEEDS_PROVIDER, true)) {
            return;
        }

        $missing = $this->keys->missing() ?? ('app:federate' === $name ? $this->keys->missingForRerank() : null);
        if (null !== $missing) {
            $event->getOutput()->writeln('<error>'.$missing.'</error>');
            $event->disableCommand();
        }
    }
}
