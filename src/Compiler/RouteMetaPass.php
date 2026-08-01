<?php

declare(strict_types=1);

namespace Survos\FieldBundle\Compiler;

use Survos\AtlasBundle\Compiler\ControllerAtlasBuilder;
use Survos\AtlasBundle\Model\RouteEntry;
use Survos\FieldBundle\Attribute\ControllerMeta;
use Survos\FieldBundle\Attribute\RouteMeta;
use Survos\FieldBundle\Model\RouteMetaDescriptor;
use Survos\FieldBundle\Registry\RouteMetaRegistry;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * Builds the RouteMetaRegistry from atlas-bundle's controller atlas.
 *
 * Routes whose #[Route] lacks an explicit name: are skipped by atlas-bundle and
 * therefore silently absent here. That is intentional — see atlas-bundle's
 * conventions. If a #[RouteMeta] never shows up in the registry, check that
 * its companion #[Route] declares a name.
 */
final class RouteMetaPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(RouteMetaRegistry::class)) {
            return;
        }

        $descriptors = [];
        // Every named route from the atlas, covered or not -- RouteMetaRegistry
        // only ever sees the covered subset (routes without #[RouteMeta] are
        // filtered out below), so coverage-gap reporting (RouteSitemapCommand)
        // needs this separate, unfiltered list.
        $allRoutes = [];
        // Class-level #[Route] prefix per controller, e.g. TenantController's
        // '/{tenantId}' -- shown next to each controller's heading in the
        // sitemap so "AccController" reads as "AccController (/{tenantId}/{accId})".
        $classPrefixes = [];

        foreach (ControllerAtlasBuilder::build($container) as $route) {
            $hits = $route->attributesOf(RouteMeta::class);

            $allRoutes[] = [
                'name'            => $route->name,
                'path'            => $route->path,
                'controller'      => $route->controller(),
                'controllerClass' => $route->controllerClass,
                'hasMeta'         => $hits !== [],
            ];

            if (!\array_key_exists($route->controllerClass, $classPrefixes)) {
                $classPrefixes[$route->controllerClass] = self::classRoutePrefix($route);
            }

            if ($hits === []) {
                continue;
            }

            // Merge class-level ControllerMeta defaults UNDER method-level RouteMeta.
            // Use raw args (only fields the user explicitly set), so unspecified
            // class-level fields do not stomp over method-level constructor defaults.
            $merged = array_merge(self::classMetaArgs($route), $hits[0]['args']);
            $meta = new RouteMeta(...$merged);

            $descriptors[] = new RouteMetaDescriptor(
                name:            $route->name,
                path:            $route->path,
                methods:         $route->methods,
                controller:      $route->controller(),
                description:     $meta->description,
                entity:          $meta->entity,
                relatedEntities: $meta->relatedEntities,
                params:          $meta->params,
                purpose:         $meta->purpose,
                label:           $meta->label,
                audience:        $meta->audience,
                sitemap:         $meta->sitemap,
                changefreq:      $meta->changefreq,
                priority:        $meta->priority,
                tags:            $meta->tags,
                parents:         $meta->parents,
            );
        }

        usort(
            $descriptors,
            static fn (RouteMetaDescriptor $a, RouteMetaDescriptor $b) => $a->name <=> $b->name,
        );

        $definitions = array_map(self::toDefinition(...), $descriptors);

        $container->getDefinition(RouteMetaRegistry::class)
            ->setArgument('$descriptors', $definitions);

        $container->setParameter('field.route_meta_count', count($definitions));

        usort($allRoutes, static fn (array $a, array $b) => $a['controller'] <=> $b['controller']);
        $container->setParameter('field.all_routes', $allRoutes);
        $container->setParameter('field.controller_prefixes', $classPrefixes);
    }

    /**
     * @return array<int|string, mixed>
     */
    private static function classMetaArgs(RouteEntry $route): array
    {
        foreach ($route->classAttributes as $attr) {
            if ($attr['class'] === ControllerMeta::class) {
                return $attr['args'];
            }
        }
        return [];
    }

    /** Class-level #[Route(...)] path, if the controller has one. */
    private static function classRoutePrefix(RouteEntry $route): ?string
    {
        foreach ($route->classAttributes as $attr) {
            if ($attr['class'] === \Symfony\Component\Routing\Attribute\Route::class) {
                $args = $attr['args'];
                $path = $args['path'] ?? $args[0] ?? null;
                return \is_string($path) ? $path : null;
            }
        }
        return null;
    }

    private static function toDefinition(RouteMetaDescriptor $d): Definition
    {
        return new Definition(RouteMetaDescriptor::class, [
            '$name'            => $d->name,
            '$path'            => $d->path,
            '$methods'         => $d->methods,
            '$controller'      => $d->controller,
            '$description'     => $d->description,
            '$entity'          => $d->entity,
            '$relatedEntities' => $d->relatedEntities,
            '$params'          => $d->params,
            '$purpose'         => $d->purpose,
            '$label'           => $d->label,
            '$audience'        => $d->audience,
            '$sitemap'         => $d->sitemap,
            '$changefreq'      => $d->changefreq,
            '$priority'        => $d->priority,
            '$tags'            => $d->tags,
            '$parents'         => $d->parents,
        ]);
    }
}
