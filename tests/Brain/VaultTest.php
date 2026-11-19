<?php

namespace App\Tests\Brain;

use App\Brain\Vault;
use App\Tests\Support\TempDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VaultTest extends TestCase
{
    use TempDirectory;

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidPaths(): iterable
    {
        yield 'parent folder' => ['brain/../../.env'];
        yield 'wrong extension' => ['brain/topics/x.txt'];
        yield 'absolute path' => ['/etc/passwd.md'];
        yield 'double slash' => ['brain//x.md'];
        yield 'outside brain and inbox' => ['other/x.md'];
        yield 'uppercase' => ['brain/Topics/X.md'];
        yield 'space' => ['brain/my note.md'];
        yield 'trailing newline' => ["brain/log.md\n"];
        yield 'leading dot' => ['brain/.hidden.md'];
        yield 'dots inside' => ['brain/a..b.md'];
        yield 'empty' => [''];
    }

    #[DataProvider('invalidPaths')]
    public function testRejectsInvalidPathsOnRead(string $path): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Vault($this->root))->read($path);
    }

    #[DataProvider('invalidPaths')]
    public function testRejectsInvalidPathsOnCreate(string $path): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Vault($this->root))->create($path, 'x');
    }

    public function testCreateWritesAndReadReturnsTheContent(): void
    {
        $vault = new Vault($this->root);
        $vault->create('brain/topics/a.md', "# A\n");

        self::assertSame("# A\n", $vault->read('brain/topics/a.md'));
    }

    public function testCreateNeverOverwrites(): void
    {
        $vault = new Vault($this->root);
        $vault->create('inbox/a.md', 'first');

        try {
            $vault->create('inbox/a.md', 'second');
            self::fail('A second create must fail.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('already exists', $e->getMessage());
        }
        self::assertSame('first', $vault->read('inbox/a.md'));
    }

    public function testReadOfAMissingNoteFails(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('There is no note');

        (new Vault($this->root))->read('brain/topics/ghost.md');
    }

    public function testReadRefusesANoteOverTheSizeLimit(): void
    {
        file_put_contents($this->root.'/brain/huge.md', str_repeat('a', 1024 * 1024 + 1));
        file_put_contents($this->root.'/brain/limit.md', str_repeat('a', 1024 * 1024));
        $vault = new Vault($this->root);

        self::assertSame(1024 * 1024, \strlen($vault->read('brain/limit.md')));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('larger than 1024 KB');
        $vault->read('brain/huge.md');
    }

    public function testListReturnsSortedMarkdownNotesOnly(): void
    {
        mkdir($this->root.'/brain/topics');
        file_put_contents($this->root.'/brain/topics/b.md', '');
        file_put_contents($this->root.'/brain/a.md', '');
        file_put_contents($this->root.'/brain/readme.txt', '');
        file_put_contents($this->root.'/inbox/c.md', '');

        self::assertSame(['brain/a.md', 'brain/topics/b.md', 'inbox/c.md'], (new Vault($this->root))->list());
    }

    public function testASymlinkedNoteIsNeitherListedNorRead(): void
    {
        $outside = $this->root.'-outside.md';
        file_put_contents($outside, 'secret');
        symlink($outside, $this->root.'/brain/leak.md');
        $vault = new Vault($this->root);

        try {
            self::assertNotContains('brain/leak.md', $vault->list());
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('leads outside the vault');
            $vault->read('brain/leak.md');
        } finally {
            unlink($outside);
        }
    }

    public function testCreateThroughASymlinkedFolderIsRefused(): void
    {
        $outside = $this->root.'-outside';
        mkdir($outside);
        symlink($outside, $this->root.'/inbox/out');

        try {
            (new Vault($this->root))->create('inbox/out/x.md', 'x');
            self::fail('A symlinked folder must not be written through.');
        } catch (\InvalidArgumentException) {
            self::assertFileDoesNotExist($outside.'/x.md');
        } finally {
            @unlink($outside.'/x.md');
            rmdir($outside);
        }
    }

    public function testUpdateRewritesAnExistingNote(): void
    {
        $vault = new Vault($this->root);
        $vault->create('brain/log.md', 'one');
        $vault->update('brain/log.md', static fn (string $content): string => $content.' two');

        self::assertSame('one two', $vault->read('brain/log.md'));
    }

    public function testUpdateCreatesTheNoteFromTheInitialContent(): void
    {
        $vault = new Vault($this->root);
        $vault->update('brain/log.md', static fn (string $content): string => $content.'!', 'start');

        self::assertSame('start!', $vault->read('brain/log.md'));
    }

    public function testUpdateWithoutInitialContentFailsForAMissingNote(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Vault($this->root))->update('brain/log.md', static fn (string $content): string => $content);
    }

    public function testUpdateTruncatesWhenTheNewContentIsShorter(): void
    {
        $vault = new Vault($this->root);
        $vault->create('brain/log.md', 'a long long content');
        $vault->update('brain/log.md', static fn (): string => 'short');

        self::assertSame('short', $vault->read('brain/log.md'));
    }
}
