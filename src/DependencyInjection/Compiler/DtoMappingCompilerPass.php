<?php

declare(strict_types=1);

namespace Survos\FieldBundle\DependencyInjection\Compiler;

use Survos\FieldBundle\Attribute\Map;
use Survos\FieldBundle\Attribute\Mapper;
use Survos\FieldBundle\Service\MappingRegistry;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Scans all classes tagged with #[Mapper] (or registered as DTO services),
 * reflects on their #[Map] property attributes, and builds the alias map
 * stored in MappingRegistry.
 *
 * Runs once at container compile time — zero per-request reflection.
 */
final class DtoMappingCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $map = [];

        foreach ($container->getParameter('dto_mapping.classes') as $class) {
            if (!class_exists($class)) {
                continue;
            }

            $map[$class] = $this->buildClassMap($class);
        }

        $container->getDefinition(MappingRegistry::class)
            ->setArgument('$map', $map);
    }

    /** @return array<string, mixed> */
    private function buildClassMap(string $class): array
    {
        $classMap = [];
        $regexEntries = [];

        $ref = new \ReflectionClass($class);

        // Walk the full hierarchy so inherited #[Map] properties are included
        foreach ($this->allProperties($ref) as $property) {
            $propName = $property->getName();

            foreach ($property->getAttributes(Map::class, \ReflectionAttribute::IS_INSTANCEOF) as $attr) {
                /** @var Map $map */
                $map = $attr->newInstance();

                if ($map->source !== null) {
                    $classMap[$map->source] = $propName;
                }

                if ($map->regex !== null) {
                    $regexEntries[] = [$map->regex, $propName];
                }
            }

            // Always include the property name itself as an alias
            $classMap[$propName] = $propName;
        }

        if ($regexEntries !== []) {
            $classMap['__regex__'] = $regexEntries;
        }

        return $classMap;
    }

    /** @return \ReflectionProperty[] */
    private function allProperties(\ReflectionClass $ref): array
    {
        $props = [];
        $seen  = [];

        do {
            foreach ($ref->getProperties() as $prop) {
                if (!isset($seen[$prop->getName()])) {
                    $props[]              = $prop;
                    $seen[$prop->getName()] = true;
                }
            }
        } while ($ref = $ref->getParentClass());

        return $props;
    }
}
