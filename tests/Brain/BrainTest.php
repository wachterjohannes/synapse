<?php

namespace App\Tests\Brain;

use App\Ai\ProviderKey;
use App\Brain\Brain;
use App\Brain\OkfFormat;
use App\Brain\OkfValidator;
use App\Brain\Reindexer;
use App\Brain\Vault;
use App\Tests\Support\FakeIndexer;
use App\Tests\Support\TempDirectory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Store\StoreInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class BrainTest extends TestCase
{
    use TempDirectory;

    private const DECISION = "# Use hyphens\n\n## Context\n\nNames vary.\n\n## Decision\n\nHyphens.\n\n## Consequences\n\nReadable paths.\n";

    private MockClock $clock;
    private Brain $brain;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-18 23:30:00', 'Europe/Vienna');
        $this->brain = $this->brain();
    }

    public function testSaveToBrainWritesFrontmatterThenBody(): void
    {
        $path = $this->brain->saveToBrain('decisions/use-hyphens.md', 'Decision', 'Use hyphens', 'File names use hyphens.', ['naming'], self::DECISION, ['https://example.com/a']);

        self::assertSame('brain/decisions/use-hyphens.md', $path);
        $file = (string) file_get_contents($this->root.'/'.$path);
        self::assertStringStartsWith("---\ntype: Decision\n", $file);
        self::assertStringContainsString("status: draft\n", $file);
        self::assertStringContainsString("generated:\n  by: synapse/1.0\n  at: 2026-11-18T22:30:00Z\n", $file);
        self::assertStringContainsString("sources:\n  - id: s1\n    resource: \"https://example.com/a\"\n", $file);
        self::assertStringEndsWith(rtrim(self::DECISION)."\n", $file);
    }

    public function testSavedNoteReadsBackAsDraft(): void
    {
        $this->brain->saveToBrain('topics/a.md', 'Topic', 'A "quoted" title', 'Colon: inside.', ['a'], "# A \"quoted\" title\n");
        $note = $this->brain->note('brain/topics/a.md');

        self::assertSame('draft', $note->status);
        self::assertSame('A "quoted" title', $note->title);
        self::assertSame('Colon: inside.', $note->description);
        self::assertSame('synapse/1.0', $note->generatedBy);
    }

    public function testNewlinesInTitleAndDescriptionCannotInjectFrontmatter(): void
    {
        $this->brain->saveToBrain('topics/a.md', 'Topic', "A\nstatus: verified", 'One.', ['a'], "# A\nstatus: verified\n");

        self::assertSame('draft', $this->brain->note('brain/topics/a.md')->status);
    }

    public function testSaveToBrainWritesTheLogWithTheNewestDayOnTop(): void
    {
        $this->brain->saveToBrain('topics/a.md', 'Topic', 'A', 'a.', ['a'], "# A\n");
        $this->clock->modify('+1 day');
        $this->brain->saveToBrain('topics/b.md', 'Topic', 'B', 'b.', ['b'], "# B\n");
        $this->brain->saveToBrain('topics/c.md', 'Topic', 'C', 'c.', ['c'], "# C\n");

        self::assertSame(
            "# Update log\n\n## 2026-11-19\n\n* added brain/topics/c.md\n* added brain/topics/b.md\n\n## 2026-11-18\n\n* added brain/topics/a.md\n\n",
            (string) file_get_contents($this->root.'/brain/log.md'),
        );
    }

    public function testALogWithoutATitleStillGetsTheEntry(): void
    {
        file_put_contents($this->root.'/brain/log.md', "no title here\n");
        $this->brain->saveToBrain('topics/a.md', 'Topic', 'A', 'a.', ['a'], "# A\n");

        $log = (string) file_get_contents($this->root.'/brain/log.md');
        self::assertStringStartsWith("# Update log\n\n## 2026-11-18\n\n* added brain/topics/a.md\n", $log);
        self::assertStringContainsString('no title here', $log);
    }

    public function testAMissingLogIsCreated(): void
    {
        $this->brain->saveToBrain('topics/a.md', 'Topic', 'A', 'a.', ['a'], "# A\n");

        self::assertFileExists($this->root.'/brain/log.md');
    }

    public function testNeverReplacesAnExistingNote(): void
    {
        $this->brain->saveToBrain('topics/a.md', 'Topic', 'A', 'first.', ['a'], "# A\n");

        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->brain->saveToBrain('topics/a.md', 'Topic', 'A', 'second.', ['a'], "# A\n");
        } finally {
            self::assertSame('first.', $this->brain->note('brain/topics/a.md')->description);
        }
    }

    public function testRejectsALinkToANoteThatDoesNotExist(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('/topics/nothing.md');

        $this->brain->saveToBrain('topics/h.md', 'Topic', 'H', 'h.', ['h'], "# H\n\nSee [x](/topics/nothing.md).\n");
    }

    public function testAcceptsALinkToAnExistingNote(): void
    {
        $this->brain->saveToBrain('topics/a.md', 'Topic', 'A', 'a.', ['a'], "# A\n");
        $this->brain->saveToBrain('topics/b.md', 'Topic', 'B', 'b.', ['b'], "# B\n\nSee [A](/topics/a.md).\n");

        self::assertStringContainsString('(/topics/a.md)', $this->brain->read('brain/topics/b.md'));
    }

    public function testAnInvalidNoteIsNotWritten(): void
    {
        try {
            $this->brain->saveToBrain('topics/a.md', 'Topic', 'A', '', ['a'], "# A\n");
            self::fail('Expected a rejection.');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $this->brain->listAll());
        }
    }

    public function testASourceIsNumberedInTheOrderItWasPassed(): void
    {
        $this->brain->saveToBrain('topics/a.md', 'Topic', 'A', 'a.', ['a'], "# A\n\nOne.[^s1] Two.[^s2]\n", ['https://example.com/1', 'https://example.com/2']);

        self::assertSame([['id' => 's1', 'resource' => 'https://example.com/1'], ['id' => 's2', 'resource' => 'https://example.com/2']], $this->brain->note('brain/topics/a.md')->sources);
    }

    public function testSetStatusChangesOnlyTheStatusLine(): void
    {
        $this->brain->saveToBrain('decisions/use-hyphens.md', 'Decision', 'Use hyphens', 'File names use hyphens.', ['naming'], self::DECISION, ['https://example.com/a']);
        $before = $this->brain->read('brain/decisions/use-hyphens.md');

        self::assertSame('draft', $this->brain->setStatus('brain/decisions/use-hyphens.md', 'verified'));
        self::assertSame(str_replace("status: draft\n", "status: verified\n", $before), $this->brain->read('brain/decisions/use-hyphens.md'));
    }

    public function testSetStatusLogsAChangeButNotARepeat(): void
    {
        $this->brain->saveToBrain('topics/a.md', 'Topic', 'A', 'a.', ['a'], "# A\n");
        $this->brain->setStatus('brain/topics/a.md', 'verified');
        $this->brain->setStatus('brain/topics/a.md', 'verified');

        $log = (string) file_get_contents($this->root.'/brain/log.md');
        self::assertSame(1, substr_count($log, 'status of brain/topics/a.md: draft to verified'));
        self::assertStringNotContainsString('verified to verified', $log);
    }

    public function testSetStatusAddsAMissingStatusLine(): void
    {
        file_put_contents($this->root.'/brain/topics.md', '');
        mkdir($this->root.'/brain/topics');
        unlink($this->root.'/brain/topics.md');
        file_put_contents($this->root.'/brain/topics/nostatus.md', "---\ntype: Topic\ntitle: \"X\"\n---\n\n# X\n");

        self::assertSame('', $this->brain->setStatus('brain/topics/nostatus.md', 'stable'));
        self::assertSame("---\ntype: Topic\ntitle: \"X\"\nstatus: stable\n---\n\n# X\n", file_get_contents($this->root.'/brain/topics/nostatus.md'));
        self::assertStringContainsString('status of brain/topics/nostatus.md: none to stable', (string) file_get_contents($this->root.'/brain/log.md'));
    }

    public function testSetStatusLeavesABodyLineThatLooksLikeAStatusAlone(): void
    {
        file_put_contents($this->root.'/brain/note.md', "---\ntype: Topic\nstatus: draft\n---\n\n# X\n\nstatus: draft\n");
        $this->brain->setStatus('brain/note.md', 'stable');

        self::assertSame("---\ntype: Topic\nstatus: stable\n---\n\n# X\n\nstatus: draft\n", file_get_contents($this->root.'/brain/note.md'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function badStatusTargets(): iterable
    {
        yield 'unknown status' => ['brain/topics/a.md', 'approved'];
        yield 'injected line' => ['brain/topics/a.md', "draft\nx: y"];
        yield 'log.md' => ['brain/log.md', 'stable'];
        yield 'index.md' => ['brain/index.md', 'stable'];
        yield 'inbox note' => ['inbox/x.md', 'stable'];
        yield 'missing note' => ['brain/topics/ghost.md', 'stable'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badStatusTargets')]
    public function testSetStatusRejectsBadInput(string $path, string $status): void
    {
        $this->brain->saveToBrain('topics/a.md', 'Topic', 'A', 'a.', ['a'], "# A\n");
        file_put_contents($this->root.'/inbox/x.md', "---\nstatus: draft\n---\n");
        $before = $this->brain->read('brain/topics/a.md');

        try {
            $this->brain->setStatus($path, $status);
            self::fail('Expected a rejection.');
        } catch (\InvalidArgumentException) {
            self::assertSame($before, $this->brain->read('brain/topics/a.md'));
        }
    }

    public function testSetStatusRefusesANoteWithoutFrontmatterAndLeavesItAsItIs(): void
    {
        file_put_contents($this->root.'/brain/plain.md', "# No frontmatter\n");

        try {
            $this->brain->setStatus('brain/plain.md', 'stable');
            self::fail('Expected a rejection.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('no frontmatter', $e->getMessage());
            self::assertSame("# No frontmatter\n", file_get_contents($this->root.'/brain/plain.md'));
        }
    }

    public function testSaveToInboxUsesTheUtcDayAndTheSlug(): void
    {
        self::assertSame('inbox/2026-11-18-idea.md', $this->brain->saveToInbox('idea', "text\n\n\n"));
        self::assertSame("text\n", $this->brain->read('inbox/2026-11-18-idea.md'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badSlugs(): iterable
    {
        yield 'slash' => ['a/b'];
        yield 'uppercase' => ['Idea'];
        yield 'leading dash' => ['-a'];
        yield 'trailing newline' => ["a\n"];
        yield 'empty' => [''];
        yield 'dots' => ['../x'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badSlugs')]
    public function testSaveToInboxRejectsABadSlug(string $slug): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->brain->saveToInbox($slug, 'x');
    }

    public function testNotesListsFinishedNotesOnlyAndSkipsFilesTheVaultRefuses(): void
    {
        $this->brain->saveToBrain('topics/a.md', 'Topic', 'A', 'a.', ['a'], "# A\n");
        file_put_contents($this->root.'/brain/index.md', "# Index\n");
        mkdir($this->root.'/brain/topics', 0o775, true);
        file_put_contents($this->root.'/brain/topics/README.md', "# A readme\n");
        file_put_contents($this->root.'/brain/topics/My Note.md', "# Spaces\n");
        file_put_contents($this->root.'/inbox/raw.md', 'raw');

        self::assertSame(['brain/topics/a.md'], array_map(static fn ($note): string => $note->path, $this->brain->notes()));
    }

    public function testASavedNoteTriggersAReindexAndAStatusChangeToo(): void
    {
        $calls = [];
        $store = $this->createStub(StoreInterface::class);
        $store->method('clear')->willReturnCallback(static function () use (&$calls): void {
            $calls[] = 'clear';
        });
        $indexer = new FakeIndexer(static function () use (&$calls): void {
            $calls[] = 'index';
        });
        $brain = $this->brain(new Reindexer($indexer, $store, new ProviderKey('cohere', 'key', ''), new LockFactory(new InMemoryStore()), new NullLogger()));

        $brain->saveToBrain('topics/a.md', 'Topic', 'A', 'a.', ['a'], "# A\n");
        self::assertSame(['clear', 'index'], $calls);

        $brain->setStatus('brain/topics/a.md', 'stable');
        self::assertSame(['clear', 'index', 'clear', 'index'], $calls);

        $brain->setStatus('brain/topics/a.md', 'stable');
        self::assertCount(4, $calls, 'A repeated status changes nothing, so nothing is rebuilt.');
    }

    public function testAFailingReindexDoesNotFailTheSave(): void
    {
        $indexer = new FakeIndexer(static fn () => throw new \RuntimeException('provider down'));
        $brain = $this->brain(new Reindexer($indexer, $this->createStub(StoreInterface::class), new ProviderKey('cohere', 'key', ''), new LockFactory(new InMemoryStore()), new NullLogger()));

        self::assertSame('brain/topics/a.md', $brain->saveToBrain('topics/a.md', 'Topic', 'A', 'a.', ['a'], "# A\n"));
    }

    /**
     * "verified" has to mean that a person looked at the note, so no tool of an agent or of the MCP server may set a status.
     */
    public function testNoToolCanReachTheStatus(): void
    {
        $files = array_merge(glob(\dirname(__DIR__, 2).'/src/Ai/*.php') ?: [], glob(\dirname(__DIR__, 2).'/src/Mcp/*.php') ?: []);

        self::assertNotEmpty($files);
        foreach ($files as $file) {
            self::assertDoesNotMatchRegularExpression('/setStatus|OkfValidator::STATUSES|validateStatus/', (string) file_get_contents($file), basename($file).' must not touch the status.');
        }
    }

    public function testTheToolsOnlyWriteDrafts(): void
    {
        $this->brain->saveToBrain('topics/a.md', 'Topic', 'A', 'a.', ['a'], "# A\n");

        self::assertSame('draft', $this->brain->note('brain/topics/a.md')->status);
    }

    private function brain(?Reindexer $reindexer = null): Brain
    {
        return new Brain(new Vault($this->root), $this->clock, new OkfValidator(new OkfFormat(\dirname(__DIR__, 2).'/config/okf.yaml')), $reindexer);
    }
}
