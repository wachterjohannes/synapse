<?php

namespace App\Ai;

use App\Brain\Brain;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[McpTool(name: 'list_notes', description: 'Lists the paths of all notes in the brain and in the inbox.', annotations: new ToolAnnotations(readOnlyHint: true))]
#[AsTool('list_notes', 'Lists the paths of all notes in the brain and in the inbox.')]
final class ListNotes
{
    public function __construct(private readonly Brain $brain)
    {
    }

    public function __invoke(): string
    {
        return implode("\n", $this->brain->listAll()) ?: 'No notes yet.';
    }
}
