<?php

namespace App\Mcp;

use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Exception\ToolCallException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Runs in front of every tool call. A tool that does not declare itself read-only
 * (`readOnlyHint: true`) needs ROLE_BRAIN_WRITE, so a new tool is closed to readers
 * until someone says that it is safe for them.
 */
final class ToolGuard implements ReferenceHandlerInterface
{
    public function __construct(
        private readonly ReferenceHandlerInterface $inner,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    public function handle(ElementReference $reference, array $arguments): mixed
    {
        if ($reference instanceof ToolReference
            && true !== $reference->tool->annotations?->readOnlyHint
            && !$this->authorizationChecker->isGranted('ROLE_BRAIN_WRITE')
        ) {
            throw new ToolCallException('This token may only read. Writing needs the brain:write scope.');
        }

        return $this->inner->handle($reference, $arguments);
    }
}
