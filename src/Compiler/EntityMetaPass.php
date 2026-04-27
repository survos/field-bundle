<?php

declare(strict_types=1);

namespace Survos\FieldBundle\Compiler;

use Survos\FieldBundle\Attribute\EntityMeta;
use Survos\FieldBundle\Model\EntityMetaDescriptor;
use Survos\FieldBundle\Registry\EntityMetaRegistry;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\Finder\Finder;

/**
 * Scans all entity directories for #[EntityMeta] at compile time.
 *
 * Discovery order (all merged):
 *   1. %kernel.project_dir%/src/Entity  — always included
 *   2. Every bundle's src/Entity dir    — auto-detected via kernel.bundles
 *   3. field.entity_dirs parameter      — explicit overrides/extras
 */
final class EntityMetaPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(EntityMetaRegistry::class)) {
            return;
        }

        $scanDirs = $this->resolveScanDirs($container);
        $rows = [];

        foreach ($scanDirs as $dir) {
            foreach ($this->fqcnsIn($dir) as $fqcn) {
                if (!class_exists($fqcn)) {
                    continue;
                }

                $rc = new \ReflectionClass($fqcn);
                $metaAttrs = $rc->getAttributes(EntityMeta::class);
                if (empty($metaAttrs)) {
                    continue;
                }

                /** @var EntityMeta $meta */
                $meta = $metaAttrs[0]->newInstance();

                if (!$meta->adminBrowsable) {
                    continue;
                }

                $rows[] = [
                    'class'          => $fqcn,
                    'label'          => $meta->label ?? $rc->getShortName(),
                    'group'          => $meta->group,
                    'order'          => $meta->order,
                    'icon'           => $meta->icon,
                    'iconClass'      => $meta->iconClass,
                    'description'    => $meta->description,
                    'adminBrowsable' => $meta->adminBrowsable,
                    'hasApiResource' => $this->hasAttribute($rc, 'ApiPlatform\\Metadata\\ApiResource'),
                    'hasMeiliIndex'  => $this->hasAttribute($rc, 'Survos\\MeiliBundle\\Metadata\\MeiliIndex'),
                ];
            }
        }

        // Sort: group alphabetically, then by order within group
        usort($rows, fn ($a, $b) => [$a['group'], $a['order'], $a['label']] <=> [$b['group'], $b['order'], $b['label']]);

        // Convert to container-serializable Definition objects (positional args match constructor order)
        $descriptors = array_map(function (array $row): Definition {
            $def = new Definition(EntityMetaDescriptor::class, [
                $row['class'],
                $row['label'],
                $row['group'],
                $row['order'],
                $row['icon'],
                $row['iconClass'],
                $row['description'],
                $row['adminBrowsable'],
                $row['hasApiResource'],
                $row['hasMeiliIndex'],
            ]);
            return $def;
        }, $rows);

        $container->getDefinition(EntityMetaRegistry::class)
            ->setArgument('$descriptors', $descriptors);

        $container->setParameter('field.entity_meta_count', count($descriptors));
    }

    /** @return string[] */
    private function resolveScanDirs(ContainerBuilder $container): array
    {
        $dirs = [];

        // 1. App's own entity dir
        $projectDir = $container->getParameter('kernel.project_dir');
        $appEntityDir = $projectDir . '/src/Entity';
        if (is_dir($appEntityDir)) {
            $dirs[] = $appEntityDir;
        }

        // 2. All bundle Entity dirs — zero-config for bundle authors
        foreach ($container->getParameter('kernel.bundles') as $bundleClass) {
            try {
                $bundleDir = dirname((new \ReflectionClass($bundleClass))->getFileName());
                $entityDir = $bundleDir . '/Entity';
                if (is_dir($entityDir)) {
                    $dirs[] = $entityDir;
                }
            } catch (\ReflectionException) {
                continue;
            }
        }

        // 3. Explicit extras from field.entity_dirs parameter
        if ($container->hasParameter('field.entity_dirs')) {
            foreach ((array) $container->getParameter('field.entity_dirs') as $dir) {
                if (is_dir($dir)) {
                    $dirs[] = $dir;
                }
            }
        }

        return array_unique($dirs);
    }

    /** Yield FQCNs from .php files without autoloading them. */
    private function fqcnsIn(string $dir): iterable
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach ((new Finder())->files()->in($dir)->name('*.php') as $file) {
            $src = @file_get_contents($file->getPathname());
            if ($src === false) {
                continue;
            }

            if (!preg_match('/namespace\s+([^;]+);/m', $src, $ns)) {
                continue;
            }

            if (!preg_match('/\b(?:final\s+|abstract\s+|readonly\s+)*class\s+([A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)\b/m', $src, $cls)) {
                continue;
            }

            yield trim($ns[1]) . '\\' . trim($cls[1]);
        }
    }

    private function hasAttribute(\ReflectionClass $rc, string $attributeClass): bool
    {
        if (!class_exists($attributeClass)) {
            return false;
        }
        return !empty($rc->getAttributes($attributeClass));
    }
}
