<?php

declare(strict_types=1);

namespace Survos\FieldBundle;

use Survos\CoreBundle\Traits\HasConfigurableRoutes;
use Survos\FieldBundle\Command\MetaExportCommand;
use Survos\FieldBundle\Compiler\EntityMetaPass;
use Survos\FieldBundle\Compiler\RouteMetaPass;
use Survos\FieldBundle\Controller\EntityDashboardController;
use Survos\FieldBundle\Registry\EntityMetaRegistry;
use Survos\FieldBundle\Registry\RouteMetaRegistry;
use Survos\FieldBundle\Service\FieldReader;
use Survos\FieldBundle\Service\RouteIdentityValueResolver;
use Survos\FieldBundle\Twig\EntityGlobalsExtension;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class SurvosFieldBundle extends AbstractBundle
{
    use HasConfigurableRoutes;

    public function configure(DefinitionConfigurator $definition): void
    {
        $children = $definition->rootNode()->children();
        $this->addRouteOptions($children, '');
        $children->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $this->captureRouteConfig($config);
        $this->registerRouteLoader($builder);

        $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure()
            ->set(FieldReader::class)->public()
            ->set(EntityMetaRegistry::class)->public()->arg('$descriptors', [])
            ->set(RouteMetaRegistry::class)->public()->arg('$descriptors', [])
            ->set(EntityGlobalsExtension::class)
            ->set(MetaExportCommand::class)
            // ValueResolverInterface — autoconfigure tags it as
            // controller.argument_value_resolver. Closes the URL→entity
            // loop for any entity carrying #[RouteIdentity], removing the
            // need for #[MapEntity(mapping: ...)] on every controller.
            ->set(RouteIdentityValueResolver::class);

        // Controller — registered via $builder so we can pass a NULL_ON_INVALID_REFERENCE
        // for the optional MeiliRegistry service (lives in survos/meili-bundle).
        $builder->autowire(EntityDashboardController::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setPublic(true)
            ->addTag('controller.service_arguments')
            ->setArgument(
                '$meiliRegistry',
                new Reference('Survos\\MeiliBundle\\Registry\\MeiliRegistry', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            )
            ->setArgument(
                '$chatWorkspaceResolver',
                new Reference('Survos\\MeiliBundle\\Service\\ChatWorkspaceResolver', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            );
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new EntityMetaPass());
        $container->addCompilerPass(new RouteMetaPass());
        $this->addRouteLoaderCompilerPass($container);
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Aliases referenced by @SurvosField/entity/dashboard.html.twig.
        // Apps can override any of these in their own ux_icons.yaml.
        if ($builder->hasExtension('ux_icons')) {
            $builder->prependExtensionConfig('ux_icons', [
                'aliases' => [
                    'api'      => 'tabler:api',
                    'database' => 'mdi:database',
                    'meili'    => 'mdi:database-search',
                    'search'   => 'tabler:search',
                ],
            ]);
        }
    }
}
