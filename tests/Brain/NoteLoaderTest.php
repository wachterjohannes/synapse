<?php

namespace App\Tests\Brain;

use App\Brain\NoteLoader;
use App\Tests\Support\TempDirectory;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Store\Document\Metadata;

final class NoteLoaderTest extends TestCase
{
    use TempDirectory;

    public function testLoadsRealNotesWithThePathTheToolsUse(): void
    {
        mkdir($this->root.'/brain/topics');
        file_put_contents($this->root.'/brain/topics/rag.md', "# RAG\n\nText.\n");
        file_put_contents($this->root.'/brain/a.md', "# A\n");

        self::assertSame(['brain/a.md', 'brain/topics/rag.md'], $this->sources());
    }

    public function testReservedFilesStayOutOfTheIndex(): void
    {
        mkdir($this->root.'/brain/topics');
        file_put_contents($this->root.'/brain/index.md', "# Index\n");
        file_put_contents($this->root.'/brain/log.md', "# Log\n");
        file_put_contents($this->root.'/brain/topics/index.md', "# Nested index\n");
        file_put_contents($this->root.'/brain/topics/real.md', "# Real\n");

        self::assertSame(['brain/topics/real.md'], $this->sources());
    }

    public function testSymlinksAreNotFollowed(): void
    {
        $outside = $this->root.'-outside';
        mkdir($outside);
        file_put_contents($outside.'/secret.md', "# Secret\n");
        file_put_contents($this->root.'/brain/real.md', "# Real\n");
        symlink($outside.'/secret.md', $this->root.'/brain/leak.md');
        symlink($this->root.'/brain/real.md', $this->root.'/brain/alias.md');
        symlink($outside, $this->root.'/brain/linked-folder');

        try {
            self::assertSame(['brain/real.md'], $this->sources());
        } finally {
            unlink($outside.'/secret.md');
            rmdir($outside);
        }
    }

    public function testOtherFileTypesAreIgnored(): void
    {
        file_put_contents($this->root.'/brain/notes.txt', 'x');
        file_put_contents($this->root.'/brain/real.md', "# Real\n");

        self::assertSame(['brain/real.md'], $this->sources());
    }

    public function testAMissingFolderLoadsNothing(): void
    {
        self::assertSame([], iterator_to_array((new NoteLoader())->load($this->root.'/nowhere'), false));
    }

    /**
     * @return list<string>
     */
    private function sources(): array
    {
        $sources = [];
        foreach ((new NoteLoader())->load($this->root.'/brain') as $document) {
            $sources[] = $document->getMetadata()[Metadata::KEY_SOURCE];
        }
        sort($sources);

        return $sources;
    }
}
