<?php

namespace App\Tests\Brain;

use App\Brain\Note;
use PHPUnit\Framework\TestCase;

final class NoteTest extends TestCase
{
    public function testParsesAFullNote(): void
    {
        $content = <<<'MD'
            ---
            type: Topic
            title: "MCP auth"
            description: "How tokens work."
            tags: [mcp, auth]
            status: verified
            generated:
              by: synapse/1.0
              at: "2026-11-18T07:00:00Z"
            sources:
              - id: s1
                resource: "https://example.com/a"
            ---

            # MCP auth

            Body.
            MD;

        $note = Note::parse('brain/topics/mcp-auth.md', $content."\n");

        self::assertSame('Topic', $note->type);
        self::assertSame('MCP auth', $note->title);
        self::assertSame('How tokens work.', $note->description);
        self::assertSame(['mcp', 'auth'], $note->tags);
        self::assertSame('verified', $note->status);
        self::assertSame('synapse/1.0', $note->generatedBy);
        self::assertSame('2026-11-18T07:00:00Z', $note->generatedAt);
        self::assertSame([['id' => 's1', 'resource' => 'https://example.com/a']], $note->sources);
        self::assertStringStartsWith('# MCP auth', $note->body);
    }

    public function testANoteWithoutFrontmatterKeepsItsBodyAndTakesTheTitleFromThePath(): void
    {
        $note = Note::parse('brain/topics/plain-note.md', "# Plain\n\nText\n");

        self::assertSame('plain-note', $note->title);
        self::assertSame('', $note->type);
        self::assertSame('', $note->status);
        self::assertSame("# Plain\n\nText\n", $note->body);
    }

    public function testBrokenFrontmatterIsShownWithoutItsData(): void
    {
        $note = Note::parse('brain/topics/broken.md', "---\ntitle: [unclosed\n  : : :\n---\n\n# Broken\n");

        self::assertSame('broken', $note->title);
        self::assertSame("# Broken\n", $note->body);
    }

    public function testEmptyFrontmatterIsAccepted(): void
    {
        $note = Note::parse('brain/topics/empty.md', "---\n---\n# Empty\n");

        self::assertSame('empty', $note->title);
        self::assertSame("# Empty\n", $note->body);
    }

    public function testFrontmatterThatIsNotAMapIsIgnored(): void
    {
        $note = Note::parse('brain/topics/list.md', "---\n- a\n- b\n---\nText\n");

        self::assertSame('list', $note->title);
    }

    public function testOnlyPlainTagsAreKept(): void
    {
        $note = Note::parse('brain/topics/weird.md', "---\ntags: [a, [b], {c: d}, ~, '', ok, 3]\n---\n# W\n");

        self::assertSame(['a', 'ok', '3'], $note->tags);
    }

    public function testTagsThatAreNotAListGiveNoTags(): void
    {
        self::assertSame([], Note::parse('brain/topics/x.md', "---\ntags: just-text\n---\n# X\n")->tags);
    }

    public function testOnlySourcesWithPlainIdAndResourceAreKept(): void
    {
        $content = "---\nsources:\n  - id: [x]\n    resource: [y]\n  - id: s2\n    resource: https://example.com\n  - just text\n  - id: s3\n---\n# W\n";

        self::assertSame([['id' => 's2', 'resource' => 'https://example.com']], Note::parse('brain/topics/w.md', $content)->sources);
    }

    public function testAnUnquotedTimestampIsFormattedAsUtc(): void
    {
        $note = Note::parse('brain/topics/t.md', "---\ngenerated:\n  by: synapse/1.0\n  at: 2026-11-18T07:00:00Z\n---\n# T\n");

        self::assertSame('2026-11-18T07:00:00Z', $note->generatedAt);
    }

    public function testNonScalarValuesBecomeEmptyStrings(): void
    {
        $note = Note::parse('brain/topics/x.md', "---\ntype: [a]\ntitle: {a: b}\ndescription: [x]\nstatus: [y]\n---\n# X\n");

        self::assertSame('', $note->type);
        self::assertSame('x', $note->title);
        self::assertSame('', $note->description);
        self::assertSame('', $note->status);
    }

    public function testGeneratedThatIsAStringDoesNotBreakParsing(): void
    {
        $note = Note::parse('brain/topics/x.md', "---\ngenerated: yesterday\n---\n# X\n");

        self::assertSame('', $note->generatedBy);
        self::assertSame('', $note->generatedAt);
    }
}
