<?php

namespace App\Tests\Ai;

use App\Ai\ToolError;
use PHPUnit\Framework\TestCase;

final class ToolErrorTest extends TestCase
{
    public function testItPrintsAsTextTheAgentsRead(): void
    {
        self::assertSame('Error: nope', (string) new ToolError('nope'));
    }

    public function testItTakesTheMessageOfAnException(): void
    {
        $error = ToolError::of(new \InvalidArgumentException('bad path'));

        self::assertSame('bad path', $error->reason);
        self::assertSame('Error: bad path', (string) $error);
    }

    public function testItIsAnObjectAndNotJustAString(): void
    {
        self::assertNotSame('Error: x', new ToolError('x'), 'ToolGuard tells an error from a note by type, never by the text.');
    }
}
