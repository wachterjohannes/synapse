<?php

namespace App\Tests\Functional;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * The live list of notes: search, filter, counts and the status change in the row. The calls go through the
 * firewall like the browser's own, so what is checked is also who may make them.
 */
final class NotesBrowserTest extends BrowserTestCase
{
    use InteractsWithLiveComponents;

    private string $notes = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->notes = (string) $_SERVER['BRAIN_DIR'];
        (new Filesystem())->mkdir($this->notes.'/brain/topics');
        $this->note('alpha', 'Alpha topic', 'About apples.', 'draft', 'fruit');
        $this->note('beta', 'Beta topic', 'About bananas.', 'verified', 'fruit, yellow');
        $this->note('gamma', 'Gamma topic', 'About cars.', 'draft', 'machine');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove([$this->notes.'/brain/topics/alpha.md', $this->notes.'/brain/topics/beta.md', $this->notes.'/brain/topics/gamma.md', $this->notes.'/brain/log.md']);
        parent::tearDown();
    }

    private function note(string $slug, string $title, string $description, string $status, string $tags): void
    {
        file_put_contents($this->notes.'/brain/topics/'.$slug.'.md', "---\ntype: Topic\ntitle: \"$title\"\ndescription: \"$description\"\ntags: [$tags]\nstatus: $status\n---\n\n# $title\n\nText.\n");
    }

    private function component(?string $query = null, ?string $status = null): \Symfony\UX\LiveComponent\Test\TestLiveComponent
    {
        self::ensureKernelShutdown();

        return $this->createLiveComponent('NotesBrowser', array_filter(['query' => $query, 'status' => $status], static fn (?string $v): bool => null !== $v), $this->browser(self::fromThePage()))
            ->actingAs(new InMemoryUser('synapse', self::PASSWORD, ['ROLE_UI']), 'ui');
    }

    /**
     * @return list<string> the titles of the rows
     */
    private static function titles(string $html): array
    {
        preg_match_all('#<a href="[^"]*ui/note[^"]*" class="font-serif[^>]*>([^<]*)</a>#', $html, $m);

        return $m[1];
    }

    public function testThePageHostsTheLiveComponent(): void
    {
        $browser = $this->browser(self::basicAuth());
        $browser->request('GET', '/ui/notes');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-controller~="live"]');
        self::assertSelectorExists('input[type="search"][data-model]');
        self::assertSelectorTextContains('body', 'Alpha topic');
        self::assertResponseHasHeader('Content-Security-Policy');
    }

    public function testTheStatusInTheAddressFiltersThePageOnTheFirstLoad(): void
    {
        $browser = $this->browser(self::basicAuth());
        $browser->request('GET', '/ui/notes?status=verified');

        self::assertSelectorTextContains('body', 'Beta topic');
        self::assertSelectorTextNotContains('body', 'Alpha topic');
    }

    public function testItListsEveryNoteWithCounts(): void
    {
        $html = (string) $this->component()->render();

        self::assertSame(['Alpha topic', 'Beta topic', 'Gamma topic'], self::titles($html));
        self::assertMatchesRegularExpression('#draft <span class="opacity-70">2</span>#', $html);
        self::assertMatchesRegularExpression('#verified <span class="opacity-70">1</span>#', $html);
    }

    public function testTheSearchReadsTitleDescriptionAndTags(): void
    {
        self::assertSame(['Alpha topic'], self::titles((string) $this->component()->set('query', 'APPLES')->render()));
        self::assertSame(['Beta topic'], self::titles((string) $this->component()->set('query', 'yellow')->render()));
        self::assertSame(['Gamma topic'], self::titles((string) $this->component()->set('query', 'gamma')->render()));
        self::assertStringContainsString('No note matches "nothing"', html_entity_decode((string) $this->component()->set('query', 'nothing')->render()));
    }

    public function testTheCountsFollowTheSearchButNotTheFilter(): void
    {
        $html = (string) $this->component()->set('query', 'fruit')->set('status', 'draft')->render();

        self::assertSame(['Alpha topic'], self::titles($html));
        self::assertMatchesRegularExpression('#verified <span class="opacity-70">1</span>#', $html, 'The verified button still says what it would show.');
    }

    public function testTheFilterActionShowsOneStatus(): void
    {
        $html = (string) $this->component()->call('filter', ['status' => 'verified'])->render();

        self::assertSame(['Beta topic'], self::titles($html));
        self::assertStringContainsString('aria-pressed="true"', $html);
    }

    public function testAStatusThatIsNotOneFiltersNothing(): void
    {
        self::assertCount(3, self::titles((string) $this->component()->set('status', 'bogus')->render()));
    }

    public function testAPersonChangesTheStatusInTheRow(): void
    {
        $html = (string) $this->component()->call('setStatus', ['path' => 'brain/topics/alpha.md', 'to' => 'stable'])->render();

        self::assertStringContainsString('status: stable', (string) file_get_contents($this->notes.'/brain/topics/alpha.md'));
        self::assertStringContainsString('draft to stable', (string) file_get_contents($this->notes.'/brain/log.md'));
        self::assertStringContainsString('Saved. The change is in log.md.', $html);
    }

    public function testAnUnknownStatusIsRefusedAndTheNoteStaysAsItIs(): void
    {
        $before = (string) file_get_contents($this->notes.'/brain/topics/alpha.md');
        $html = (string) $this->component()->call('setStatus', ['path' => 'brain/topics/alpha.md', 'to' => 'approved'])->render();

        self::assertSame($before, file_get_contents($this->notes.'/brain/topics/alpha.md'));
        self::assertStringContainsString('role="alert"', $html);
    }

    public function testOnlyAFinishedNoteHasAStatus(): void
    {
        foreach (['brain/log.md', 'brain/index.md', 'inbox/x.md', 'brain/../../.env'] as $path) {
            $html = (string) $this->component()->call('setStatus', ['path' => $path, 'to' => 'stable'])->render();

            self::assertStringContainsString('role="alert"', $html, $path);
        }
        self::assertFileDoesNotExist($this->notes.'/brain/log.md');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function endpoints(): iterable
    {
        yield 'a re-render' => ['/ui/_components/NotesBrowser'];
        yield 'an action' => ['/ui/_components/NotesBrowser/setStatus'];
        yield 'the batch' => ['/ui/_components/NotesBrowser/_batch'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('endpoints')]
    public function testTheEndpointsNeedALogin(string $url): void
    {
        $browser = $this->browser();
        $browser->request('POST', $url, server: self::fromThePage() + ['HTTP_ACCEPT' => 'application/vnd.live-component+html']);

        // the live component turns a redirect into a header of its own, and the browser follows it
        $response = $browser->getResponse();
        self::assertTrue($response->isRedirect('/login') || '1' === $response->headers->get('X-Live-Redirect'), 'Sent to the login page.');
        self::assertStringContainsString('status: draft', (string) file_get_contents($this->notes.'/brain/topics/alpha.md'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('endpoints')]
    public function testAPostNeedsTheSameOriginMark(string $url): void
    {
        $browser = $this->browser(self::basicAuth() + ['HTTP_ACCEPT' => 'application/vnd.live-component+html']);
        $browser->request('POST', $url, ['data' => json_encode(['props' => [], 'actions' => [['name' => 'setStatus', 'args' => ['path' => 'brain/topics/alpha.md', 'to' => 'stable']]]])]);

        self::assertResponseStatusCodeSame(403);
        self::assertStringContainsString('status: draft', (string) file_get_contents($this->notes.'/brain/topics/alpha.md'));
    }

    public function testACrossSitePostIsRefused(): void
    {
        $browser = $this->browser(self::basicAuth() + ['HTTP_SEC_FETCH_SITE' => 'cross-site']);
        $browser->request('POST', '/ui/_components/NotesBrowser/setStatus');

        self::assertResponseStatusCodeSame(403);
    }

    public function testTheEndpointsStayClosedWhileThePasswordIsEmpty(): void
    {
        $this->withEnv('UI_PASSWORD', '', function (): void {
            $browser = $this->browser();
            $browser->request('POST', '/ui/_components/NotesBrowser/setStatus', server: self::fromThePage());

            self::assertStringEndsWith('/login', (string) $browser->getResponse()->headers->get('Location'));
        });
    }

    public function testTheResponseCarriesTheHeadersOfThePage(): void
    {
        $headers = $this->component()->refresh()->response()->headers;

        self::assertStringContainsString("script-src 'self' 'nonce-", (string) $headers->get('Content-Security-Policy'));
        self::assertSame('DENY', $headers->get('X-Frame-Options'));
    }
}
