<?php

namespace App\Mcp;

use Mcp\Capability\Registry\ReferenceHandler;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Puts the ToolGuard around the handler the MCP bundle gives every server. The bundle has no
 * setting for this in 1.0.0-BETA1, so the pass reads the service locator it hands to the builder.
 */
final class ToolGuardPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        foreach ($container->getDefinitions() as $id => $builder) {
            if (!preg_match('/^mcp\.server\.[^.]+\.builder$/', $id)) {
                continue;
            }

            $locator = null;
            foreach ($builder->getMethodCalls() as [$method, $arguments]) {
                if ('setReferenceHandler' === $method) {
                    throw new \LogicException(sprintf('"%s" already has a reference handler, wrap it instead.', $id));
                }
                if ('setContainer' === $method) {
                    $locator = $arguments[0];
                }
            }
            if (null === $locator) {
                continue; // a server without services has no tools to guard
            }

            $builder->addMethodCall('setReferenceHandler', [new Definition(ToolGuard::class, [
                new Definition(ReferenceHandler::class, [$locator]),
                new Reference('security.authorization_checker'),
            ])]);
        }
    }
}
