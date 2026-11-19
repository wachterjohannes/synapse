<?php

namespace App\Twig\Components;

use App\Brain\Brain;
use App\Brain\Note;
use App\Brain\OkfValidator;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The list of notes with a search box, a status filter and the status change in the row, all without a page load.
 *
 * It lives below /ui/, so the firewall and the headers of the page cover it, and GuardLiveComponents puts the same
 * same-origin check in front of every call that is not a plain read. Only a person gets here, and the status is
 * changed through Brain::setStatus like everywhere else, which is not a tool of any agent.
 */
#[AsLiveComponent]
final class NotesBrowser
{
    use DefaultActionTrait;

    #[LiveProp(writable: true, url: true)]
    public string $query = '';

    #[LiveProp(writable: true, url: true)]
    public string $status = '';

    /** What the last status change said, for the row that was changed. */
    #[LiveProp]
    public string $changed = '';

    #[LiveProp]
    public string $error = '';

    public function __construct(private readonly Brain $brain)
    {
    }

    /**
     * @return list<string>
     */
    public function statuses(): array
    {
        return OkfValidator::STATUSES;
    }

    /**
     * The notes that match the search and the filter, by type.
     *
     * @return array<string, list<Note>>
     */
    public function groups(): array
    {
        $status = $this->activeStatus();
        $groups = [];
        foreach ($this->brain->notes() as $note) {
            if (('' === $status || $note->status === $status) && $this->matches($note)) {
                $groups[$note->type ?: 'Other'][] = $note;
            }
        }
        ksort($groups);

        return $groups;
    }

    /**
     * How many notes have each status. The search counts, the filter does not, so a button says what it would show.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = array_fill_keys(OkfValidator::STATUSES, 0);
        foreach ($this->brain->notes() as $note) {
            if ($this->matches($note)) {
                $counts[$note->status] = ($counts[$note->status] ?? 0) + 1;
            }
        }

        return $counts;
    }

    public function total(): int
    {
        return array_sum($this->counts());
    }

    /** A value the browser sent that is not a status filters nothing and shows nothing. */
    public function activeStatus(): string
    {
        return \in_array($this->status, OkfValidator::STATUSES, true) ? $this->status : '';
    }

    #[LiveAction]
    public function filter(#[LiveArg] string $status = ''): void
    {
        $this->status = $status;
    }

    #[LiveAction]
    public function setStatus(#[LiveArg] string $path, #[LiveArg] string $to): void
    {
        $this->changed = $this->error = '';
        try {
            $this->brain->setStatus($path, $to);
            $this->changed = $path;
        } catch (\InvalidArgumentException $e) {
            $this->error = $e->getMessage();
        }
    }

    private function matches(Note $note): bool
    {
        $query = trim($this->query);
        if ('' === $query) {
            return true;
        }

        return false !== mb_stripos(implode("\n", [$note->title, $note->description, $note->path, implode(' ', $note->tags)]), $query);
    }
}
