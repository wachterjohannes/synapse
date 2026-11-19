<?php

namespace App\Controller;

use App\Ai\ProviderKey;
use App\Security\UiGuard;
use App\Voice\Speech;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Twig\Environment;

/**
 * A browser page as an alternative to the commands. It calls the same two agents, so everything that is written
 * still goes through the Vault. Meant for your own machine: it is protected by HTTP basic auth with UI_PASSWORD and
 * stays off while that is empty (see UiGuard).
 *
 * The answer to a question streams: the form gets a turbo stream with an empty answer card, the card opens an
 * event stream (turbo-stream-source), and every word of the agent arrives there as one more turbo stream.
 *
 * Every call that costs money has a limit on the size of its input and counts against a rate limit.
 */
final class UiController
{
    private const STREAM = 'text/vnd.turbo-stream.html';

    /** characters */
    private const MAX_CAPTURE = 8000;
    private const MAX_QUESTION = 1000;
    private const MAX_SPEAK = 4000;
    /** bytes of a recording, about five minutes of speech */
    private const MAX_AUDIO = 10 * 1024 * 1024;

    public function __construct(
        #[Target('capturer')]
        private readonly AgentInterface $capturer,
        #[Target('reader')]
        private readonly AgentInterface $reader,
        private readonly Speech $speech,
        private readonly Environment $twig,
        private readonly UiGuard $ui,
        private readonly ProviderKey $keys,
        private readonly CacheInterface $cache,
    ) {
    }

    #[Route('/', name: 'ui_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->ui->disabled() ?? new Response($this->twig->render('ui/index.html.twig'));
    }

    #[Route('/ui/capture', name: 'ui_capture', methods: ['POST'])]
    public function capture(Request $request): Response
    {
        if (null !== $response = $this->ui->guard($request)) {
            return $response;
        }
        $text = trim($request->request->getString('text'));

        $reply = match (true) {
            '' === $text => 'Write something first.',
            mb_strlen($text) > self::MAX_CAPTURE => sprintf('That is too long. A note can have %d characters, yours has %d.', self::MAX_CAPTURE, mb_strlen($text)),
            null !== $missing = $this->keys->missing() => $missing,
            default => null,
        };
        if (null === $reply) {
            if (null !== $limited = $this->ui->throttle($request)) {
                return $limited;
            }
            try {
                $reply = $this->capturer->call($text)->getContent();
            } catch (\Throwable $e) {
                $reply = 'That did not work: '.$e->getMessage();
            }
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
        $problem = match (true) {
            '' === $question => 'Ask something first.',
            mb_strlen($question) > self::MAX_QUESTION => sprintf('That question is too long. It can have %d characters, yours has %d.', self::MAX_QUESTION, mb_strlen($question)),
            default => $this->keys->missing(),
        };

        // A question that cannot be answered gets its card with the reason and no event stream, so nothing is called.
        return new Response($this->twig->render('ui/ask.stream.html.twig', [
            'id' => bin2hex(random_bytes(4)),
            'question' => $question,
            'problem' => $problem,
        ]), headers: ['Content-Type' => self::STREAM]);
    }

    #[Route('/ui/ask/{id}/stream', name: 'ui_ask_stream', requirements: ['id' => '[0-9a-f]{8}'], methods: ['GET'])]
    public function askStream(Request $request, string $id): Response
    {
        if (null !== $response = $this->ui->guard($request)) {
            return $response;
        }
        $question = trim($request->query->getString('q'));
        if ('' === $question || mb_strlen($question) > self::MAX_QUESTION || null !== $this->keys->missing()) {
            return new JsonResponse(['error' => 'No valid question.'], 400);
        }

        // Every stream is for one answer. When the connection drops, a browser asks again by itself, and a second
        // request for the same id would be a second paid call. 204 tells it to stop.
        $first = false;
        $this->cache->get('ui_stream_'.$id, static function (ItemInterface $item) use (&$first): bool {
            $item->expiresAfter(600);
            $first = true;

            return true;
        });
        if (!$first) {
            return new Response('', Response::HTTP_NO_CONTENT);
        }
        if (null !== $limited = $this->ui->throttle($request)) {
            return $limited;
        }

        return new StreamedResponse(function () use ($id, $question): void {
            // PHP's output buffering would hold the words back until the answer is complete.
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            set_time_limit(120);
            // do not reconnect for ten minutes if the connection is lost
            echo "retry: 600000\n\n";
            flush();

            $waiting = true;
            $stopWaiting = function () use (&$waiting, $id): void {
                if ($waiting) {
                    $waiting = false;
                    $this->send('<turbo-stream action="remove" target="answer-'.$id.'-wait"></turbo-stream>');
                }
            };
            try {
                foreach ($this->reader->call($question, ['stream' => true])->getContent() as $delta) {
                    // the stream opens with empty deltas, long before the first word: the hint stays until there is text
                    if ($delta instanceof TextDelta && '' !== $delta->getText()) {
                        $stopWaiting();
                        $this->send($this->fragment('append', 'answer-'.$id.'-text', htmlspecialchars($delta->getText())));
                    }
                }
            } catch (\Throwable $e) {
                $stopWaiting();
                $this->send($this->fragment('append', 'answer-'.$id.'-text', htmlspecialchars("\n\n(".$e->getMessage().')')));
            }
            $stopWaiting();
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
        if (!$audio instanceof UploadedFile) {
            return new JsonResponse(['error' => 'No audio.'], 400);
        }
        if (\in_array($audio->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true) || $audio->getSize() > self::MAX_AUDIO) {
            return new JsonResponse(['error' => sprintf('That recording is too long. The limit is %d MB.', self::MAX_AUDIO / 1024 / 1024)], 413);
        }
        if (!$audio->isValid() || 0 === $audio->getSize()) {
            return new JsonResponse(['error' => 'The recording did not arrive. Try again.'], 400);
        }
        $type = (string) $audio->getMimeType();
        if (!str_starts_with($type, 'audio/') && !\in_array($type, ['video/webm', 'video/mp4', 'application/ogg'], true)) {
            return new JsonResponse(['error' => 'That is not an audio file.'], 415);
        }
        if (null !== $limited = $this->ui->throttle($request)) {
            return $limited;
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
        $body = json_decode($request->getContent(), true);
        $text = \is_array($body) && \is_string($body['text'] ?? null) ? trim($body['text']) : '';
        if ('' === $text) {
            return new JsonResponse(['error' => 'No text.'], 400);
        }
        if (mb_strlen($text) > self::MAX_SPEAK) {
            return new JsonResponse(['error' => sprintf('That is too long to read aloud. The limit is %d characters.', self::MAX_SPEAK)], 413);
        }
        if (null !== $limited = $this->ui->throttle($request)) {
            return $limited;
        }

        try {
            return new Response($this->speech->speak($text), headers: ['Content-Type' => 'audio/mpeg']);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 502);
        }
    }

    /**
     * One server-sent event. Every line of the turbo stream needs its own "data:" prefix. A lone carriage return would
     * also end a line for the browser, so all line breaks become "\n" first.
     */
    private function send(string $turboStream): void
    {
        $turboStream = str_replace(["\r\n", "\r"], "\n", rtrim($turboStream));
        echo 'data: '.str_replace("\n", "\ndata: ", $turboStream)."\n\n";
        flush();
    }

    private function fragment(string $action, string $target, string $html): string
    {
        return \sprintf('<turbo-stream action="%s" target="%s"><template>%s</template></turbo-stream>', $action, $target, $html);
    }
}
