<?php

namespace App\Tests\Markdown;

use App\Brain\Vault;
use App\Markdown\MarkdownRenderer;
use App\Tests\Support\TempDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class MarkdownRendererTest extends TestCase
{
    use TempDirectory;

    private ?MarkdownRenderer $renderer = null;

    private function renderer(): MarkdownRenderer
    {
        return $this->renderer ??= $this->build();
    }

    private function build(): MarkdownRenderer
    {
        $vault = new Vault($this->root);
        $vault->create('brain/topics/rag-basics.md', "# RAG\n");
        $vault->create('brain/index.md', "# Index\n");
        $vault->create('inbox/idea.md', "An idea\n");

        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn (string $name, array $parameters): string => '/ui/note?path='.$parameters['path']);

        return new MarkdownRenderer($urls, $vault);
    }

    public function testItRendersMarkdown(): void
    {
        $html = $this->renderer()->render("**bold** and `code`\n\n- one\n- two\n");

        self::assertStringContainsString('<strong>bold</strong>', $html);
        self::assertStringContainsString('<code>code</code>', $html);
        self::assertStringContainsString('<li>two</li>', $html);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hostile(): iterable
    {
        yield 'script tag' => ['Before <script>alert(1)</script> after'];
        yield 'image with a handler' => ['<img src=x onerror=alert(1)>'];
        yield 'iframe' => ['<iframe src="https://evil.example"></iframe>'];
        yield 'html comment' => ["<!-- hidden -->\n\ntext"];
        yield 'javascript link' => ['[click](javascript:alert(1))'];
        yield 'javascript link in capitals' => ['[click](JaVaScRiPt:alert(1))'];
        yield 'javascript link as an entity' => ['[click](&#106;avascript:alert(1))'];
        yield 'vbscript link' => ['[click](vbscript:msgbox(1))'];
        yield 'data link' => ['[click](data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==)'];
        yield 'autolink' => ['<javascript:alert(1)>'];
        yield 'reference link' => ["[click][x]\n\n[x]: javascript:alert(1)"];
        yield 'link title that closes the attribute' => ['[a](https://example.com "x\" onmouseover=\"alert(1)")'];
        yield 'code that closes the template of a turbo stream' => ['`</template><script>alert(1)</script>`'];
        yield 'text that closes the template of a turbo stream' => ['</template><turbo-stream action="remove" target="answers"></turbo-stream>'];
    }

    #[DataProvider('hostile')]
    public function testHostileInputNeverRunsAnything(string $markdown): void
    {
        foreach ([false, true] as $keepLineBreaks) {
            $html = $this->renderer()->render($markdown, $keepLineBreaks);

            $this->assertInert($html);
        }
    }

    /**
     * Looks at the parsed page, not at the text: an attribute value that merely contains "onmouseover=" is harmless.
     */
    private function assertInert(string $html): void
    {
        $document = new \DOMDocument();
        // a turbo stream puts this HTML into a <template>, so a closing tag in the text would end it early
        $document->loadHTML('<body>'.$html.'</body>', \LIBXML_NOERROR);
        foreach ($document->getElementsByTagName('*') as $element) {
            self::assertContains($element->nodeName, ['html', 'body', 'p', 'a', 'code', 'strong', 'em', 'ul', 'ol', 'li', 'br', 'pre', 'blockquote', 'h1', 'h2', 'h3', 'h4', 'hr', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'del']);
            foreach ($element->attributes as $attribute) {
                self::assertStringStartsNotWith('on', $attribute->name);
                self::assertContains($attribute->name, ['href', 'title', 'class', 'align', 'start']);
            }
        }
        foreach ($document->getElementsByTagName('a') as $link) {
            self::assertDoesNotMatchRegularExpression('/^\s*(javascript|vbscript|data):/i', $link->getAttribute('href'));
        }
        self::assertStringNotContainsString('</template', strtolower($html));
    }

    public function testASourceThatExistsBecomesALink(): void
    {
        $html = $this->renderer()->render("Answer.\n\nSource: brain/topics/rag-basics.md");

        self::assertStringContainsString('<a href="/ui/note?path=brain/topics/rag-basics.md">brain/topics/rag-basics.md</a>', $html);
    }

    public function testASourceInBackticksBecomesALink(): void
    {
        $html = $this->renderer()->render('See `brain/topics/rag-basics.md`.');

        self::assertStringContainsString('<a href="/ui/note?path=brain/topics/rag-basics.md"><code>brain/topics/rag-basics.md</code></a>', $html);
    }

    public function testSeveralSourcesOnSeparateLines(): void
    {
        $html = $this->renderer()->render("Source: brain/topics/rag-basics.md\nSource: brain/topics/rag-basics.md.", keepLineBreaks: true);

        self::assertSame(2, substr_count($html, '<a href='));
        self::assertStringContainsString("<br>\n", $html);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function noLink(): iterable
    {
        yield 'a note that does not exist' => ['Source: brain/topics/made-up.md'];
        yield 'the index' => ['Source: brain/index.md'];
        yield 'the inbox' => ['Source: inbox/idea.md'];
        yield 'a path that leaves the vault' => ['Source: brain/../../.env.md'];
        yield 'a path inside a longer word' => ['xbrain/topics/rag-basics.md'];
        yield 'no .md' => ['brain/topics/rag-basics.txt'];
    }

    #[DataProvider('noLink')]
    public function testOnlyANoteThatExistsIsLinked(string $markdown): void
    {
        self::assertStringNotContainsString('<a ', $this->renderer()->render($markdown));
    }

    public function testAPathInsideALinkIsNotLinkedTwice(): void
    {
        $html = $this->renderer()->render('[brain/topics/rag-basics.md](https://example.com)');

        self::assertSame(1, substr_count($html, '<a '));
        self::assertStringContainsString('https://example.com', $html);
    }

    public function testALinkBetweenNotesLeadsToThePageOfTheNote(): void
    {
        $html = $this->renderer()->render('[RAG](/topics/rag-basics.md)');

        self::assertStringContainsString('href="/ui/note?path=brain/topics/rag-basics.md"', $html);
    }

    public function testALineBreakStaysASoftBreakByDefault(): void
    {
        self::assertStringNotContainsString('<br>', $this->renderer()->render("one\ntwo"));
        self::assertStringContainsString('<br>', $this->renderer()->render("one\ntwo", keepLineBreaks: true));
    }
}
