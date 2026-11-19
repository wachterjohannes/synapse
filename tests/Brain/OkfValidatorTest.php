<?php

namespace App\Tests\Brain;

use App\Brain\OkfFormat;
use App\Brain\OkfValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OkfValidatorTest extends TestCase
{
    private OkfValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new OkfValidator(new OkfFormat(\dirname(__DIR__, 2).'/config/okf.yaml'));
    }

    public function testAValidTopicPasses(): void
    {
        $this->validator->validate('topics/mcp-auth.md', 'Topic', 'MCP auth', 'How tokens work.', ['mcp'], "# MCP auth\n\nText.[^s1]\n", ['https://example.com/a']);

        $this->addToAssertionCount(1);
    }

    public function testAValidDecisionPasses(): void
    {
        $body = "# Use hyphens\n\n## Context\n\nA.\n\n## Decision\n\nB.\n\n## Consequences\n\nC.\n";
        $this->validator->validate('decisions/use-hyphens.md', 'Decision', 'Use hyphens', 'One line.', ['naming'], $body, []);

        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidNotes(): iterable
    {
        yield 'missing type' => [['type' => ''], 'needs a type'];
        yield 'unknown type' => [['type' => 'Recipe'], 'type "Recipe" is unknown'];
        yield 'path without folder' => [['path' => 'mcp.md'], 'must look like'];
        yield 'path with uppercase' => [['path' => 'topics/Mcp.md'], 'must look like'];
        yield 'path with trailing newline' => [['path' => "topics/mcp.md\n"], 'must look like'];
        yield 'reserved index' => [['path' => 'topics/index.md'], 'reserved'];
        yield 'reserved log' => [['path' => 'topics/log.md'], 'reserved'];
        yield 'wrong folder for type' => [['path' => 'people/mcp.md'], 'belongs into the folder topics/'];
        yield 'empty title' => [['title' => ' ', 'body' => "# \n"], 'needs a title'];
        yield 'multiline description' => [['description' => "a\nb"], 'one sentence on one line'];
        yield 'empty description' => [['description' => ''], 'one sentence on one line'];
        yield 'long description' => [['description' => str_repeat('a', 201)], 'at most 200'];
        yield 'no tags' => [['tags' => []], 'one to four tags'];
        yield 'five tags' => [['tags' => ['a', 'b', 'c', 'd', 'e']], 'one to four tags'];
        yield 'tag with uppercase' => [['tags' => ['Mcp']], 'must be lowercase'];
        yield 'tag with trailing newline' => [['tags' => ["mcp\n"]], 'must be lowercase'];
        yield 'source without scheme' => [['sources' => ['example.com']], 'full http(s) URL'];
        yield 'source with javascript scheme' => [['sources' => ['javascript:alert(1)']], 'full http(s) URL'];
        yield 'body without title heading' => [['body' => "Text only.\n"], 'must start with the heading "# MCP"'];
        yield 'relative note link' => [['body' => "# MCP\n\nSee [x](other.md).\n"], 'path from the brain root'];
        yield 'footnote without source' => [['body' => "# MCP\n\nClaim.[^s1]\n"], 'footnote [^s1] has no source'];
        yield 'footnote with free label' => [['body' => "# MCP\n\nClaim.[^note]\n", 'sources' => ['https://example.com']], 'footnote [^note] has no source'];
        yield 'footnote s0' => [['body' => "# MCP\n\nClaim.[^s0]\n", 'sources' => ['https://example.com']], 'footnote [^s0] has no source'];
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('invalidNotes')]
    public function testRejectsAnInvalidNote(array $override, string $message): void
    {
        $note = $override + [
            'path' => 'topics/mcp.md', 'type' => 'Topic', 'title' => 'MCP', 'description' => 'About MCP.',
            'tags' => ['mcp'], 'body' => "# MCP\n\nText.\n", 'sources' => [],
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $this->validator->validate($note['path'], $note['type'], $note['title'], $note['description'], $note['tags'], $note['body'], $note['sources']);
    }

    public function testASectionIsRequiredForADecision(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('needs a section "## Context"');

        $this->validator->validate('decisions/x.md', 'Decision', 'X', 'x.', ['x'], "# X\n\nJust text.\n", []);
    }

    public function testAllErrorsAreReportedAtOnce(): void
    {
        try {
            $this->validator->validate('Bad', '', '', '', [], '', []);
            self::fail('Expected a rejection.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('needs a type', $e->getMessage());
            self::assertStringContainsString('needs a title', $e->getMessage());
            self::assertStringContainsString('one to four tags', $e->getMessage());
        }
    }

    public function testBodyThatIsOnlyTheHeadingPasses(): void
    {
        $this->validator->validate('topics/mcp.md', 'Topic', 'MCP', 'About MCP.', ['mcp'], '# MCP', []);

        $this->addToAssertionCount(1);
    }

    #[DataProvider('statuses')]
    public function testValidateStatus(string $status, bool $valid): void
    {
        if (!$valid) {
            $this->expectException(\InvalidArgumentException::class);
        }
        $this->validator->validateStatus($status);
        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function statuses(): iterable
    {
        foreach (['draft', 'stable', 'verified', 'deprecated'] as $status) {
            yield $status => [$status, true];
        }
        yield 'unknown' => ['approved', false];
        yield 'empty' => ['', false];
        yield 'uppercase' => ['Draft', false];
        yield 'injected line' => ["draft\nx: y", false];
    }
}
