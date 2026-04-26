<?php

declare(strict_types=1);

namespace Survos\FieldBundle;

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
            ->set(FieldReader::class)
            ->public();
    }
}
