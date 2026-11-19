<?php

namespace App\Tests\Functional;

use Symfony\Component\Filesystem\Filesystem;

final class NotesPagesTest extends BrowserTestCase
{
    private string $notes = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->notes = (string) $_SERVER['BRAIN_DIR'];
        (new Filesystem())->mkdir($this->notes.'/brain/topics');
        file_put_contents($this->notes.'/brain/topics/page.md', <<<'MD'
            ---
            type: Topic
            title: "A page"
            description: "About a page."
            tags: [page]
            status: draft
            ---

            # A page

            Text with <script>alert(1)</script> and [bad](javascript:alert(1)) and [good](/topics/other.md).
            MD."\n");
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove([$this->notes.'/brain/topics/page.md', $this->notes.'/brain/log.md']);
        parent::tearDown();
    }

    public function testTheListShowsTheNotesGroupedByType(): void
    {
        $browser = $this->browser(self::basicAuth());
        $browser->request('GET', '/ui/notes');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'A page');
        self::assertResponseHasHeader('Content-Security-Policy');
    }

    public function testANoteIsShownWithoutRawHtmlAndWithoutUnsafeLinks(): void
    {
        $browser = $this->browser(self::basicAuth());
        $browser->request('GET', '/ui/note?path=brain/topics/page.md');

        self::assertResponseIsSuccessful();
        $html = (string) $browser->getResponse()->getContent();
        self::assertStringNotContainsString('<script>alert(1)', $html);
        self::assertStringNotContainsString('javascript:', $html);
        self::assertStringContainsString('/ui/note?path=brain/topics/other.md', $html);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hiddenPaths(): iterable
    {
        yield 'log' => ['brain/log.md'];
        yield 'index' => ['brain/index.md'];
        yield 'inbox' => ['inbox/x.md'];
        yield 'outside' => ['brain/../../.env'];
        yield 'missing' => ['brain/topics/ghost.md'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hiddenPaths')]
    public function testOnlyFinishedNotesAreShown(string $path): void
    {
        $browser = $this->browser(self::basicAuth());
        $browser->request('GET', '/ui/note', ['path' => $path]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testAPersonCanChangeTheStatus(): void
    {
        $browser = $this->browser(self::basicAuth() + self::fromThePage());
        $browser->request('POST', '/ui/note/status', ['path' => 'brain/topics/page.md', 'status' => 'verified']);

        self::assertResponseStatusCodeSame(303);
        self::assertStringContainsString('status: verified', (string) file_get_contents($this->notes.'/brain/topics/page.md'));
        self::assertStringContainsString('draft to verified', (string) file_get_contents($this->notes.'/brain/log.md'));
    }

    public function testAnUnknownStatusIsRefusedAndTheNoteStaysAsItIs(): void
    {
        $before = (string) file_get_contents($this->notes.'/brain/topics/page.md');
        $browser = $this->browser(self::basicAuth() + self::fromThePage());
        $browser->request('POST', '/ui/note/status', ['path' => 'brain/topics/page.md', 'status' => 'approved']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($before, file_get_contents($this->notes.'/brain/topics/page.md'));
    }

    public function testTheStatusChangeNeedsTheSameOriginMark(): void
    {
        $browser = $this->browser(self::basicAuth());
        $browser->request('POST', '/ui/note/status', ['path' => 'brain/topics/page.md', 'status' => 'verified']);

        self::assertResponseStatusCodeSame(403);
        self::assertStringContainsString('status: draft', (string) file_get_contents($this->notes.'/brain/topics/page.md'));
    }

    public function testTheNotesPagesAreClosedWithoutALogin(): void
    {
        $browser = $this->browser();
        $browser->request('GET', '/ui/notes');

        self::assertResponseRedirects('/login');
    }
}
