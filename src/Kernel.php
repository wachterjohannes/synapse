<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use App\Mcp\ToolGuardPass;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait {
        configureContainer as private configureDefaults;
    }

    /** The providers that have a file in config/providers/. */
    private const PROVIDERS = ['cohere', 'huggingface'];

    protected function build(ContainerBuilder $container): void
    {
        // after the MCP bundle has wired its servers
        $container->addCompilerPass(new ToolGuardPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -10);
    }

    /**
     * @return list<string> An array of allowed values for APP_ENV
     */
    private function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }

    /**
     * SYNAPSE_PROVIDER picks the embedding model and the agent models. It has to be known while the container is
     * built, so it is read here instead of through an env parameter.
     */
    private function configureContainer(ContainerConfigurator $container, LoaderInterface $loader, ContainerBuilder $builder): void
    {
        $this->configureDefaults($container, $loader, $builder);

        $provider = $_SERVER['SYNAPSE_PROVIDER'] ?? $_ENV['SYNAPSE_PROVIDER'] ?? 'cohere';
        if (!\in_array($provider, self::PROVIDERS, true)) {
            throw new \InvalidArgumentException(\sprintf('SYNAPSE_PROVIDER must be one of %s, got "%s".', implode(', ', self::PROVIDERS), $provider));
        }
        $container->import('../config/providers/'.$provider.'.yaml');
    }
}
