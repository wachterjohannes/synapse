<?php

namespace App\Controller;

use App\Security\UiGuard;
use App\Voice\Speech;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

/**
 * A browser page as an alternative to the commands. It calls the same two agents, so everything that is written
 * still goes through the Vault. Meant for your own machine: it is protected by HTTP basic auth with UI_PASSWORD and
 * stays off while that is empty (see UiGuard).
 *
 * The answer to a question streams: the form gets a turbo stream with an empty answer card, the card opens an
 * event stream (turbo-stream-source), and every word of the agent arrives there as one more turbo stream.
 */
final class UiController
{
    private const STREAM = 'text/vnd.turbo-stream.html';

    public function __construct(
        #[Target('capturer')]
        private readonly AgentInterface $capturer,
        #[Target('reader')]
        private readonly AgentInterface $reader,
        private readonly Speech $speech,
        private readonly Environment $twig,
        private readonly UiGuard $ui,
    ) {
    }

    #[Route('/', name: 'ui_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->ui->disabled() ?? new Response($this->twig->render('ui/index.html.twig'), headers: [
            'Content-Security-Policy' => "default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline' data:; img-src 'self' data:; media-src 'self' blob:",
        ]);
    }

    #[Route('/ui/capture', name: 'ui_capture', methods: ['POST'])]
    public function capture(Request $request): Response
    {
        if (null !== $response = $this->ui->guard($request)) {
            return $response;
        }

        try {
            $reply = $this->capturer->call(trim($request->request->getString('text')))->getContent();
        } catch (\Throwable $e) {
            $reply = 'That did not work: '.$e->getMessage();
        }

        return new Response($this->twig->render('ui/capture.stream.html.twig', ['reply' => $reply]), headers: ['Content-Type' => self::STREAM]);
    }

    #[Route('/ui/ask', name: 'ui_ask', methods: ['POST'])]
    public function ask(Request $request): Response
    {
        if (null !== $response = $this->ui->guard($request)) {
            return $response;
        }
        $question = trim($request->request->getString('question'));

        return new Response($this->twig->render('ui/ask.stream.html.twig', ['id' => bin2hex(random_bytes(4)), 'question' => $question]), headers: ['Content-Type' => self::STREAM]);
    }

    #[Route('/ui/ask/{id}/stream', name: 'ui_ask_stream', requirements: ['id' => '[0-9a-f]{8}'], methods: ['GET'])]
    public function askStream(Request $request, string $id): Response
    {
        if (null !== $response = $this->ui->guard($request)) {
            return $response;
        }
        $question = trim($request->query->getString('q'));

        return new StreamedResponse(function () use ($id, $question): void {
            // PHP's output buffering would hold the words back until the answer is complete.
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            set_time_limit(120);

            try {
                foreach ($this->reader->call($question, ['stream' => true])->getContent() as $delta) {
                    if ($delta instanceof TextDelta) {
                        $this->send($this->fragment('append', 'answer-'.$id.'-text', htmlspecialchars($delta->getText())));
                    }
                }
            } catch (\Throwable $e) {
                $this->send($this->fragment('append', 'answer-'.$id.'-text', htmlspecialchars("\n\n(".$e->getMessage().')')));
            }
            $this->send($this->twig->render('ui/done.stream.html.twig', ['id' => $id]));
        }, headers: ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache', 'X-Accel-Buffering' => 'no']);
    }

    #[Route('/ui/transcribe', name: 'ui_transcribe', methods: ['POST'])]
    public function transcribe(Request $request): Response
    {
        if (null !== $response = $this->ui->guard($request)) {
            return $response;
        }
        $audio = $request->files->get('audio');
        if (null === $audio) {
            return new JsonResponse(['error' => 'No audio.'], 400);
        }

        try {
            return new JsonResponse(['text' => $this->speech->transcribe($audio->getPathname())]);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 502);
        }
    }

    #[Route('/ui/speak', name: 'ui_speak', methods: ['POST'])]
    public function speak(Request $request): Response
    {
        if (null !== $response = $this->ui->guard($request)) {
            return $response;
        }
        $text = trim((string) ($request->toArray()['text'] ?? ''));
        if ('' === $text) {
            return new JsonResponse(['error' => 'No text.'], 400);
        }

        try {
            return new Response($this->speech->speak($text), headers: ['Content-Type' => 'audio/mpeg']);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 502);
        }
    }

    /** One server-sent event. Every line of the turbo stream needs its own "data:" prefix. */
    private function send(string $turboStream): void
    {
        echo 'data: '.str_replace("\n", "\ndata: ", rtrim($turboStream))."\n\n";
        flush();
    }

    private function fragment(string $action, string $target, string $html): string
    {
        return \sprintf('<turbo-stream action="%s" target="%s"><template>%s</template></turbo-stream>', $action, $target, $html);
    }
}
