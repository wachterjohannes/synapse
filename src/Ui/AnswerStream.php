<?php

namespace App\Ui;

use App\Markdown\MarkdownRenderer;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Twig\Environment;

/**
 * The turbo streams of one answer, in the order they are sent. The words come as plain text, one append each, so they
 * show up while the model writes. When the answer is complete, one more stream replaces that text with the same
 * answer as HTML (see MarkdownRenderer). The plain text goes along in a hidden element for "Read it aloud" and for
 * the copy button, so what is read and copied is the text of the model and not its markup.
 */
final class AnswerStream
{
    public function __construct(
        #[Target('reader')]
        private readonly AgentInterface $reader,
        private readonly MarkdownRenderer $markdown,
        private readonly Environment $twig,
        #[Autowire('%stream_answers%')]
        private readonly bool $streamAnswers,
    ) {
    }

    /**
     * @return \Generator<int, string> one turbo stream per entry
     */
    public function events(string $id, string $question): \Generator
    {
        $text = '';
        $failed = false;
        $waiting = true;
        $live = 'answer-'.$id.'-live';

        try {
            if (!$this->streamAnswers) {
                $text = (string) $this->reader->call($question)->getContent();
                yield from $this->stopWaiting($waiting, $id);
                yield $this->fragment('append', $live, htmlspecialchars($text));
            } else {
                foreach ($this->reader->call($question, ['stream' => true])->getContent() as $delta) {
                    // the stream opens with empty deltas, long before the first word: the skeleton stays until there is text
                    if ($delta instanceof TextDelta && '' !== $delta->getText()) {
                        yield from $this->stopWaiting($waiting, $id);
                        $text .= $delta->getText();
                        yield $this->fragment('append', $live, htmlspecialchars($delta->getText()));
                    }
                }
            }
        } catch (\Throwable $e) {
            $failed = true;
            $text .= "\n\n(".$e->getMessage().')';
            yield from $this->stopWaiting($waiting, $id);
            yield $this->fragment('append', $live, htmlspecialchars("\n\n(".$e->getMessage().')'));
        }
        yield from $this->stopWaiting($waiting, $id);

        // An answer that broke off stays as the plain text it is, with the reason below it.
        yield $this->twig->render('ui/done.stream.html.twig', [
            'id' => $id,
            'plain' => trim($text),
            'html' => $failed ? null : $this->markdown->render($text, keepLineBreaks: true),
        ]);
    }

    /**
     * @return \Generator<int, string>
     */
    private function stopWaiting(bool &$waiting, string $id): \Generator
    {
        if ($waiting) {
            $waiting = false;
            yield '<turbo-stream action="remove" target="answer-'.$id.'-wait"></turbo-stream>';
        }
    }

    private function fragment(string $action, string $target, string $html): string
    {
        return \sprintf('<turbo-stream action="%s" target="%s"><template>%s</template></turbo-stream>', $action, $target, $html);
    }
}
