<?php

namespace App\Ai;

use App\Brain\Brain;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[McpTool(name: 'read_note', description: 'Reads one note by the path list_notes or search_notes shows.', annotations: new ToolAnnotations(readOnlyHint: true))]
#[AsTool('read_note', 'Reads one note. Pass the path exactly as list_notes shows it, for example "brain/topics/mcp.md" or "inbox/2026-10-27-idea.md".')]
final class ReadNote
{
    public function __construct(private readonly Brain $brain)
    {
    }

    /**
     * @param string $path the path of the note, as shown by list_notes
     */
    public function __invoke(string $path): string|ToolError
    {
        try {
            return $this->brain->read($path);
        } catch (\InvalidArgumentException $e) {
            return ToolError::of($e);
        }
    }
}
