<?php

namespace App\Command;

use App\Voice\Speech;
use Symfony\AI\Agent\AgentInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Target;

#[AsCommand('app:capture', 'Hands raw material to the capture agent, which files it in the brain')]
final class CaptureCommand
{
    public function __construct(
        #[Target('capturer')]
        private readonly AgentInterface $agent,
        private readonly Speech $speech,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The raw text to capture')] ?string $text = null,
        #[Option('Capture what is said in this audio file instead')] ?string $audioIn = null,
    ): int {
        if (null !== $audioIn) {
            $text = $this->speech->transcribe($audioIn);
            $io->writeln(\sprintf('<comment>Heard: %s</comment>', $text));
        }
        if (null === $text || '' === $text) {
            $io->error('Give a text or --audio-in.');

            return 1;
        }

        $io->writeln($this->agent->call($text)->getContent());

        return 0;
    }
}
