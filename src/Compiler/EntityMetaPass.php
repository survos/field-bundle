<?php

declare(strict_types=1);

namespace Survos\FieldBundle\Compiler;

use Survos\AtlasBundle\Compiler\EntityAtlasBuilder;
use Survos\FieldBundle\Attribute\EntityMeta;
use Survos\FieldBundle\Model\EntityMetaDescriptor;
use Survos\FieldBundle\Registry\EntityMetaRegistry;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

use function Symfony\Component\String\u;

/**
 * Builds the EntityMetaRegistry from the entity atlas.
 *
 * Discovery is delegated to atlas-bundle's EntityAtlasBuilder, which scans:
 *   1. doctrine.orm.mappings directories
 *   2. %kernel.project_dir%/src/Entity
 *   3. Each registered bundle's Entity/ subdir
 *   4. field.entity_dirs parameter (extra dirs)
 */
final class EntityMetaPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(EntityMetaRegistry::class)) {
            return;
        }

        $extraDirs   = $this->resolveExtraDirs($container);
        $descriptors = [];

        foreach (EntityAtlasBuilder::build($container, $extraDirs) as $entry) {
            $hits = $entry->attributesOf(EntityMeta::class);
            if ($hits === []) {
                continue;
            }

            $meta = new EntityMeta(...$hits[0]['args']);
            if (!$meta->adminBrowsable) {
                continue;
            }

            $descriptors[] = new EntityMetaDescriptor(
                class:          $entry->fqcn,
                label:          $meta->label ?? $entry->shortName,
                group:          $meta->group,
                order:          $meta->order,
                icon:           $meta->icon,
                iconClass:      $meta->iconClass,
                description:    $meta->description,
                adminBrowsable: $meta->adminBrowsable,
                hasApiResource: $entry->hasAttribute('ApiPlatform\\Metadata\\ApiResource'),
                hasMeiliIndex:  $entry->hasAttribute('Survos\\MeiliBundle\\Metadata\\MeiliIndex'),
                code:           self::deriveCode($entry->fqcn, $entry->shortName),
                globalKey:      self::deriveGlobalKey($entry->fqcn),
            );
        }

        usort(
            $descriptors,
            static fn (EntityMetaDescriptor $a, EntityMetaDescriptor $b)
                => [$a->group, $a->order, $a->label] <=> [$b->group, $b->order, $b->label],
        );

        $definitions = array_map(self::toDefinition(...), $descriptors);

        $container->getDefinition(EntityMetaRegistry::class)
            ->setArgument('$descriptors', $definitions);

        $container->setParameter('field.entity_meta_count', count($definitions));
    }

    private static function toDefinition(EntityMetaDescriptor $d): Definition
    {
        return new Definition(EntityMetaDescriptor::class, [
            '$class'          => $d->class,
            '$label'          => $d->label,
            '$group'          => $d->group,
            '$order'          => $d->order,
            '$icon'           => $d->icon,
            '$iconClass'      => $d->iconClass,
            '$description'    => $d->description,
            '$adminBrowsable' => $d->adminBrowsable,
            '$hasApiResource' => $d->hasApiResource,
            '$hasMeiliIndex'  => $d->hasMeiliIndex,
            '$code'           => $d->code,
            '$globalKey'      => $d->globalKey,
        ]);
    }

    /**
     * Mirror SurvosUtils::entityCode() without depending on core-bundle.
     * App\Entity\Song -> "app_song"; Survos\PixieBundle\Entity\Foo -> "pixie_foo".
     */
    private static function deriveCode(string $fqcn, string $shortName): string
    {
        $parts = explode('\\', ltrim($fqcn, '\\'));
        $prefix = 'App';
        foreach ($parts as $part) {
            if ($part === 'App') { $prefix = 'App'; break; }
            if (str_ends_with($part, 'Bundle')) {
                $prefix = substr($part, 0, -6);
                break;
            }
        }

        return u($prefix)->snake()->toString() . '_' . u($shortName)->snake()->toString();
    }

    /** App\Entity\Song -> "APP_ENTITY_SONG"; Survos\PixieBundle\Entity\Foo -> "SURVOS_PIXIE_BUNDLE_ENTITY_FOO". */
    private static function deriveGlobalKey(string $fqcn): string
    {
        return u(ltrim($fqcn, '\\'))->replace('\\', '_')->snake()->upper()->toString();
    }

    /** @return list<string> */
    private function resolveExtraDirs(ContainerBuilder $container): array
    {
        if (!$container->hasParameter('field.entity_dirs')) {
            return [];
        }

        $dirs = (array) $container->getParameter('field.entity_dirs');

        return array_values(array_filter($dirs, static fn ($d) => \is_string($d) && \is_dir($d)));
    }
}
