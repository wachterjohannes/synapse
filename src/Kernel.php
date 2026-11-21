<?php

namespace App;

use App\Mcp\ToolGuardPass;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait {
        configureContainer as private configureDefaults;
        getCacheDir as private defaultCacheDir;
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
     * The provider is baked into the container, and Symfony only rebuilds a container when a config file changes.
     * A change in .env.local is not one, so every provider gets its own cache directory. Without that, switching
     * the provider keeps the old container and the old key is asked for.
     */
    public function getCacheDir(): string
    {
        return $this->defaultCacheDir().'/'.$this->provider();
    }

    /**
     * SYNAPSE_PROVIDER picks the embedding model and the agent models. It has to be known while the container is
     * built, so it is read here instead of through an env parameter.
     */
    private function configureContainer(ContainerConfigurator $container, LoaderInterface $loader, ContainerBuilder $builder): void
    {
        $this->configureDefaults($container, $loader, $builder);

        $container->import('../config/providers/'.$this->provider().'.yaml');
    }

    private function provider(): string
    {
        $provider = $_SERVER['SYNAPSE_PROVIDER'] ?? $_ENV['SYNAPSE_PROVIDER'] ?? '';
        if ('' === $provider) {
            $provider = 'cohere';
        }
        if (!\in_array($provider, self::PROVIDERS, true)) {
            throw new \InvalidArgumentException(\sprintf('SYNAPSE_PROVIDER must be one of %s, got "%s".', implode(', ', self::PROVIDERS), $provider));
        }

        return $provider;
    }
}
