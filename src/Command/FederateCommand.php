<?php

namespace App\Command;

use App\Brain\Federation;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('app:federate', 'Asks your brain and another brain, then ranks the passages of both together')]
final class FederateCommand
{
    public function __construct(private readonly Federation $federation)
    {
    }

    public function __invoke(SymfonyStyle $io, #[Argument('Your question')] string $question): int
    {
        $passages = $this->federation->search($question);
        if (null !== $this->federation->warning()) {
            $io->warning(OutputFormatter::escape($this->federation->warning()));
        }
        foreach ($passages as $rank => $passage) {
            $text = trim(preg_replace('/\s+/', ' ', (string) $passage->getMetadata()->getText()));
            // Text from the other brain is not ours: keep it from being read as console markup.
            $io->writeln(sprintf('#%d [%s] %s', $rank + 1, null === $passage->getScore() ? '-' : sprintf('%.3f', $passage->getScore()), OutputFormatter::escape((string) $passage->getMetadata()->getSource())));
            $io->writeln('   '.OutputFormatter::escape(mb_strimwidth($text, 0, 140, '...')));
        }

        return 0;
    }
}
