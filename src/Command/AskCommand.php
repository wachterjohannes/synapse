<?php

namespace App\Command;

use App\Voice\Speech;
use Symfony\AI\Agent\AgentInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Target;

#[AsCommand('app:ask', 'Asks the read agent a question about your notes')]
final class AskCommand
{
    public function __construct(
        #[Target('reader')]
        private readonly AgentInterface $agent,
        private readonly Speech $speech,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Your question')] ?string $question = null,
        #[Option('Read the question from this audio file instead')] ?string $audioIn = null,
        #[Option('Also write the answer as an MP3 to this path')] ?string $speak = null,
    ): int {
        if (null !== $audioIn) {
            $question = $this->speech->transcribe($audioIn);
            $io->writeln(\sprintf('<comment>Heard: %s</comment>', $question));
        }
        if (null === $question || '' === $question) {
            $io->error('Give a question or --audio-in.');

            return 1;
        }

        $answer = $this->agent->call($question)->getContent();
        $io->writeln($answer);
        if (null !== $speak) {
            file_put_contents($speak, $this->speech->speak($answer));
            $io->writeln(\sprintf('<info>Spoken answer: %s</info>', $speak));
        }

        return 0;
    }
}
