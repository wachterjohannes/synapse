<?php

namespace App\Tests\Ui;

use App\Brain\Vault;
use App\Markdown\MarkdownRenderer;
use App\Ui\AnswerStream;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Execution\ExecutionInterface;
use Symfony\AI\Platform\Result\Stream\Delta\MetadataDelta;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class AnswerStreamTest extends KernelTestCase
{
    /**
     * @param string|iterable<mixed> $content
     */
    private function stream(string|iterable $content, bool $streamed = true, ?\Throwable $fails = null): AnswerStream
    {
        $execution = $this->createStub(ExecutionInterface::class);
        $execution->method('getContent')->willReturn($content);
        $agent = $this->createStub(AgentInterface::class);
        $fails ? $agent->method('call')->willThrowException($fails) : $agent->method('call')->willReturn($execution);

        $container = static::getContainer();
        $markdown = new MarkdownRenderer($container->get('router'), new Vault((string) $_SERVER['BRAIN_DIR']));

        return new AnswerStream($agent, $markdown, $container->get(Environment::class), $streamed);
    }

    /**
     * @return list<string>
     */
    private function events(AnswerStream $stream): array
    {
        return iterator_to_array($stream->events('0123abcd', 'question'), false);
    }

    private static function words(): \Generator
    {
        yield new MetadataDelta('usage', 1);
        yield new TextDelta('');
        yield new TextDelta('The **bold** ');
        yield new TextDelta('word.');
    }

    public function testTheWordsAreAppendedAsPlainTextAndTheSkeletonGoesWithTheFirstOne(): void
    {
        $events = $this->events($this->stream(self::words()));

        self::assertSame('<turbo-stream action="remove" target="answer-0123abcd-wait"></turbo-stream>', $events[0]);
        self::assertSame('<turbo-stream action="append" target="answer-0123abcd-live"><template>The **bold** </template></turbo-stream>', $events[1]);
        self::assertSame('<turbo-stream action="append" target="answer-0123abcd-live"><template>word.</template></turbo-stream>', $events[2]);
    }

    public function testTheLastStreamReplacesTheTextWithHtmlAndKeepsThePlainTextForSpeechAndCopy(): void
    {
        $events = $this->events($this->stream(self::words()));
        $last = end($events);

        self::assertStringContainsString('<turbo-stream action="update" target="answer-0123abcd-text">', $last);
        self::assertStringContainsString('<strong>bold</strong>', $last);
        self::assertStringContainsString('<div hidden data-speak-target="plain" data-copy-target="plain">The **bold** word.</div>', $last);
        self::assertStringContainsString('<turbo-stream action="remove" target="answer-0123abcd-source">', $last);
        self::assertStringContainsString('data-action="copy#copy"', $last);
    }

    public function testTheTextOfTheModelIsEscapedInTheStreamsAndInTheHiddenPlainText(): void
    {
        $events = $this->events($this->stream(new \ArrayIterator([new TextDelta('<script>alert(1)</script> [x](javascript:alert(1))')])));
        $all = implode("\n", $events);

        self::assertStringNotContainsString('<script', $all);
        self::assertStringNotContainsString('href="javascript:', $all);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $events[1]);
        self::assertStringContainsString('&lt;script&gt;', (string) end($events), 'The hidden plain text is escaped by Twig.');
    }

    public function testAnAnswerThatIsNotStreamedComesInOneAppend(): void
    {
        $events = $this->events($this->stream('All at **once**.', streamed: false));

        self::assertSame('<turbo-stream action="append" target="answer-0123abcd-live"><template>All at **once**.</template></turbo-stream>', $events[1]);
        self::assertStringContainsString('<strong>once</strong>', (string) end($events));
    }

    public function testAnErrorStaysPlainTextAndKeepsTheWordsThatCameBefore(): void
    {
        $broken = (static function (): \Generator {
            yield new TextDelta('Half an answer');
            throw new \RuntimeException('The provider went away.');
        })();

        $events = $this->events($this->stream($broken));
        $last = end($events);

        self::assertStringContainsString('(The provider went away.)', implode("\n", $events));
        self::assertStringNotContainsString('target="answer-0123abcd-text"', $last, 'The plain text is not replaced.');
        self::assertStringContainsString('Half an answer', $last);
    }

    public function testACallThatFailsAtOnceStillEndsTheStream(): void
    {
        $events = $this->events($this->stream('', fails: new \RuntimeException('No route to the provider.')));

        self::assertStringContainsString('(No route to the provider.)', implode("\n", $events));
        self::assertStringContainsString('answer-0123abcd-source', (string) end($events));
    }

    public function testASourceNamedInTheAnswerIsALinkOnlyWhenTheNoteExists(): void
    {
        $dir = $_SERVER['BRAIN_DIR'].'/brain/topics';
        @mkdir($dir, 0o777, true);
        file_put_contents($dir.'/stream-source.md', "---\ntype: Topic\n---\n\nx\n");
        try {
            $events = $this->events($this->stream(new \ArrayIterator([new TextDelta("Source: brain/topics/stream-source.md\nSource: brain/topics/made-up.md")])));
            $last = (string) end($events);

            self::assertStringContainsString('<a href="/ui/note?path=brain/topics/stream-source.md">', $last);
            self::assertStringNotContainsString('path=brain/topics/made-up.md', $last);
        } finally {
            unlink($dir.'/stream-source.md');
        }
    }
}
