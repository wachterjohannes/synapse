<?php

namespace App\Command;

use App\Setup\Doctor;
use App\Setup\Level;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Checks a fresh install and says what is missing and how to fix it. It calls no provider. Exit code 1 when
 * something is an error, so a script can wait for a good setup.
 */
#[AsCommand('app:doctor', 'Checks the setup: keys, password, index and folders')]
final class DoctorCommand
{
    public function __construct(private readonly Doctor $doctor)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $errors = $warnings = 0;
        foreach ($this->doctor->checks() as $check) {
            $tag = match ($check->level) {
                Level::Ok => 'info',
                Level::Warning => 'comment',
                Level::Error => 'error',
            };
            $io->writeln(sprintf('<%1$s> %2$s </%1$s> %3$s', $tag, $check->level->symbol(), $check->title).('' === $check->detail ? '' : '  '.$check->detail));
            if (null !== $check->fix) {
                $io->writeln('    fix: '.$check->fix);
            }
            $errors += Level::Error === $check->level ? 1 : 0;
            $warnings += Level::Warning === $check->level ? 1 : 0;
        }
        $io->newLine();

        if ($errors > 0) {
            $io->error(sprintf('%d to fix, %d warning(s).', $errors, $warnings));

            return 1;
        }
        $io->success(0 === $warnings ? 'Everything is set up.' : sprintf('Ready to run, %d warning(s).', $warnings));

        return 0;
    }
}
