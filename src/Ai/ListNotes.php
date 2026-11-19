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

    /**
     * The parameter is optional and only there so that the tool has a schema with properties. Symfony AI 1.0.0-BETA1
     * leaves "parameters" out for a tool without arguments, and the Hugging Face router answers that with a 422
     * (symfony/ai#2846, fixed in #2848 after BETA1). Drop the parameter once a release contains that fix.
     *
     * @param string $folder "brain" or "inbox" to list only that folder, empty for both
     */
    public function __invoke(string $folder = ''): string
    {
        $paths = $this->brain->listAll();
        if ('' !== $folder) {
            $paths = array_values(array_filter($paths, static fn (string $path): bool => str_starts_with($path, rtrim($folder, '/').'/')));
        }

        return implode("\n", $paths) ?: 'No notes yet.';
    }
}
