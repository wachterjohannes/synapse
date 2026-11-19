<?php

namespace App\Ai;

/**
 * What a tool answers when it cannot do what it was asked. The agents read it as text and try again.
 * The MCP server turns the same text into a tool error (see ToolGuard), so a client can tell it from an answer.
 */
final class ToolError
{
    public const PREFIX = 'Error: ';

    public static function of(\Throwable $e): string
    {
        return self::PREFIX.$e->getMessage();
    }
}
