<?php

namespace App\Controller;

use App\Brain\Brain;
use App\Brain\OkfValidator;
use App\Markdown\MarkdownRenderer;
use App\Security\UiGuard;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * Looks through the notes in the browser and lets a person change the status of one. The status is the only thing
 * that can be changed here, and only through Brain::setStatus, which is not a tool of any agent.
 * The list is the live component NotesBrowser. This controller keeps the page around it, the page of one note and the
 * plain form post for a status change.
 */
final class NotesController
{
    public function __construct(
        private readonly Brain $brain,
        private readonly Environment $twig,
        private readonly UiGuard $ui,
        private readonly UrlGeneratorInterface $urls,
        private readonly MarkdownRenderer $markdown,
    ) {
    }

    #[Route('/ui/notes', name: 'ui_notes', methods: ['GET'])]
    public function list(): Response
    {
        if (null !== $off = $this->ui->disabled()) {
            return $off;
        }
        // the list is a live component, it reads ?query= and ?status= itself
        return $this->page('notes/list.html.twig', []);
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
            'html' => $this->markdown->render($this->withoutTitle($note->body)),
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

    /** The first heading repeats the title, which the page shows already. */
    private function withoutTitle(string $body): string
    {
        return preg_replace('/\A# [^\n]*\n+/', '', $body) ?? $body;
    }

    /** @param array<string, mixed> $context */
    private function page(string $template, array $context, int $status = 200): Response
    {
        // the security headers of the page come from App\EventListener\UiHeaders
        return new Response($this->twig->render($template, $context), $status);
    }
}
