<?php

namespace App\Command;

use App\Brain\Brain;
use App\Brain\OkfValidator;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The way for a person to mark a note as stable, verified or deprecated. The agents write drafts and cannot do this:
 * there is no tool for it, on purpose.
 */
#[AsCommand('app:status', 'Shows the status of the notes, or sets it for one note')]
final class StatusCommand
{
    public function __construct(private readonly Brain $brain)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The note, for example brain/topics/mcp.md or topics/mcp.md')] ?string $path = null,
        #[Argument('The new status: draft, stable, verified or deprecated')] ?string $status = null,
        #[Option('Only list the notes with this status')] ?string $list = null,
    ): int {
        if (null === $path) {
            return $this->list($io, $list);
        }
        if (null === $status) {
            $io->error('Give the new status, one of: '.implode(', ', OkfValidator::STATUSES).'. To see the notes, run the command without a path.');

            return 1;
        }

        $path = str_starts_with($path, 'brain/') ? $path : 'brain/'.$path;
        try {
            $before = $this->brain->setStatus($path, $status);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return 1;
        }
        $io->success($before === $status ? sprintf('%s already is %s.', $path, $status) : sprintf('%s: %s to %s.', $path, '' === $before ? 'none' : $before, $status));

        return 0;
    }

    private function list(SymfonyStyle $io, ?string $only): int
    {
        $rows = [];
        foreach ($this->brain->notes() as $note) {
            if (null === $only || $note->status === $only) {
                $rows[] = [$note->status ?: '-', $note->path, $note->title];
            }
        }
        if ([] === $rows) {
            $io->writeln(null === $only ? 'No notes.' : sprintf('No note has the status %s.', $only));

            return 0;
        }
        $io->table(['Status', 'Path', 'Title'], $rows);

        return 0;
    }
}
