<?php

namespace App\Ai;

/**
 * What a tool answers when it cannot do what it was asked. The agents read it as text ("Error: ...") and try again.
 * The MCP server recognizes the type, not the text (see ToolGuard), so a note that starts with "Error: " is no error.
 */
final class ToolError implements \Stringable
{
    public function __construct(public readonly string $reason)
    {
    }

    public static function of(\Throwable $e): self
    {
        return new self($e->getMessage());
    }

    public function __toString(): string
    {
        return 'Error: '.$this->reason;
    }
}
