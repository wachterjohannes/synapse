<?php

namespace App\EventListener;

use App\Ai\ProviderKey;
use App\Setup\Doctor;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * The commands that call a model stop with a clear message when the key is empty, before a single request goes out.
 * The commands that search stop the same way when the index is not built, instead of ending in an exception.
 */
#[AsEventListener(event: ConsoleEvents::COMMAND)]
final class RequireProviderKey
{
    private const NEEDS_PROVIDER = ['app:ask', 'app:capture', 'app:federate', 'ai:store:index'];
    private const NEEDS_INDEX = ['app:ask', 'app:federate'];

    public function __construct(private readonly ProviderKey $keys, private readonly Doctor $doctor)
    {
    }

    public function __invoke(ConsoleCommandEvent $event): void
    {
        $name = $event->getCommand()?->getName();
        if (!\in_array($name, self::NEEDS_PROVIDER, true)) {
            return;
        }

        $missing = $this->keys->missing()
            ?? ('app:federate' === $name ? $this->keys->missingForRerank() : null)
            ?? (\in_array($name, self::NEEDS_INDEX, true) ? $this->doctor->indexProblem() : null);
        if (null !== $missing) {
            $event->getOutput()->writeln('<error>'.$missing.'</error>');
            $event->disableCommand();
        }
    }
}
