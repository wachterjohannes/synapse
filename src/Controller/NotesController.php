<?php

namespace App\Controller;

use App\Brain\Brain;
use App\Brain\Note;
use App\Brain\OkfValidator;
use App\Security\UiGuard;
use League\CommonMark\Environment\Environment as MarkdownEnvironment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Event\DocumentParsedEvent;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * Looks through the notes in the browser and lets a person change the status of one. The status is the only thing
 * that can be changed here, and only through Brain::setStatus, which is not a tool of any agent.
 */
final class NotesController
{
    public function __construct(
        private readonly Brain $brain,
        private readonly Environment $twig,
        private readonly UiGuard $ui,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    #[Route('/ui/notes', name: 'ui_notes', methods: ['GET'])]
    public function list(Request $request): Response
    {
        if (null !== $off = $this->ui->disabled()) {
            return $off;
        }
        $filter = $request->query->getString('status');
        $groups = [];
        $counts = array_fill_keys(OkfValidator::STATUSES, 0);
        foreach ($this->brain->notes() as $note) {
            $counts[$note->status] = ($counts[$note->status] ?? 0) + 1;
            if ('' === $filter || $note->status === $filter) {
                $groups[$note->type ?: 'Other'][] = $note;
            }
        }
        ksort($groups);

        return $this->page('notes/list.html.twig', ['groups' => $groups, 'counts' => $counts, 'filter' => $filter, 'statuses' => OkfValidator::STATUSES]);
    }

    #[Route('/ui/note', name: 'ui_note', methods: ['GET'])]
    public function show(Request $request): Response
    {
        if (null !== $off = $this->ui->disabled()) {
            return $off;
        }
        try {
            $note = $this->brain->note($this->path($request->query->getString('path')));
        } catch (\InvalidArgumentException) {
            return $this->page('notes/missing.html.twig', [], 404);
        }

        return $this->page('notes/show.html.twig', [
            'note' => $note,
            'html' => $this->render($note),
            'statuses' => OkfValidator::STATUSES,
            'flash' => $request->query->getString('changed'),
        ]);
    }

    #[Route('/ui/note/status', name: 'ui_note_status', methods: ['POST'])]
    public function status(Request $request): Response
    {
        if (null !== $response = $this->ui->guard($request)) {
            return $response;
        }
        $path = $request->request->getString('path');
        try {
            $this->brain->setStatus($this->path($path), $request->request->getString('status'));
        } catch (\InvalidArgumentException $e) {
            return $this->page('notes/missing.html.twig', ['message' => $e->getMessage()], 422);
        }

        return new RedirectResponse($this->urls->generate('ui_note', ['path' => $path, 'changed' => 1]), 303);
    }

    /** Only finished notes are shown, the same rule setStatus applies. */
    private function path(string $path): string
    {
        if (!str_starts_with($path, 'brain/') || \in_array(basename($path), ['index.md', 'log.md'], true)) {
            throw new \InvalidArgumentException('Not a finished note.');
        }

        return $path;
    }

    /**
     * Note bodies come from agents and from web pages they read, so raw html is dropped and unsafe link targets
     * are not allowed. The first heading repeats the title, which the page shows already. Links between notes ("/topics/mcp.md", from the brain root) lead to the page of that note.
     */
    private function render(Note $note): string
    {
        $environment = new MarkdownEnvironment(['html_input' => 'strip', 'allow_unsafe_links' => false]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new GithubFlavoredMarkdownExtension());
        $environment->addEventListener(DocumentParsedEvent::class, function (DocumentParsedEvent $event): void {
            foreach ($event->getDocument()->iterator() as $node) {
                if ($node instanceof Link && preg_match('#^/[^\s]*\.md\z#', $node->getUrl())) {
                    $node->setUrl($this->urls->generate('ui_note', ['path' => 'brain'.$node->getUrl()]));
                }
            }
        });

        return (new MarkdownConverter($environment))->convert(preg_replace('/\A# [^\n]*\n+/', '', $note->body))->getContent();
    }

    /** @param array<string, mixed> $context */
    private function page(string $template, array $context, int $status = 200): Response
    {
        return new Response($this->twig->render($template, $context), $status, [
            'Content-Security-Policy' => "default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline' data:; img-src 'self' data:",
        ]);
    }
}
