<?php

namespace App\Ai;

use App\Brain\OkfFormat;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[McpTool(name: 'describe_format', description: 'Shows the rules for a note and, for a given type, its folder, sections and an example. Call it before save_to_brain.', annotations: new ToolAnnotations(readOnlyHint: true))]
#[AsTool('describe_format', 'Shows the rules for a note in the brain and, for a given type, its folder, sections and an example. Call it before save_to_brain.')]
final class DescribeFormat
{
    public function __construct(private readonly OkfFormat $format)
    {
    }

    /**
     * @param string $type Topic, Decision, Person or Project. Leave empty to list the types.
     */
    public function __invoke(string $type = ''): string
    {
        return $this->format->describe($type);
    }
}
