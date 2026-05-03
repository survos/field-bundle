<?php

declare(strict_types=1);

namespace Survos\FieldBundle;

use Survos\FieldBundle\Command\MetaExportCommand;
use Survos\FieldBundle\Compiler\EntityMetaPass;
use Survos\FieldBundle\Compiler\RouteMetaPass;
use Survos\FieldBundle\Registry\EntityMetaRegistry;
use Survos\FieldBundle\Registry\RouteMetaRegistry;
use Survos\FieldBundle\Service\FieldReader;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class SurvosFieldBundle extends AbstractBundle
{
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure()
            ->set(FieldReader::class)->public()
            ->set(EntityMetaRegistry::class)->public()->arg('$descriptors', [])
            ->set(RouteMetaRegistry::class)->public()->arg('$descriptors', [])
            ->set(MetaExportCommand::class);
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new EntityMetaPass());
        $container->addCompilerPass(new RouteMetaPass());
    }
}
