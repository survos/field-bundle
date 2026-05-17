<?php

declare(strict_types=1);

namespace Survos\FieldBundle\Twig;

use Survos\FieldBundle\Registry\EntityMetaRegistry;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/**
 * Exposes every #[EntityMeta]-annotated class as a Twig global keyed by its
 * compile-time globalKey, e.g. APP_ENTITY_SONG => 'App\Entity\Song'.
 *
 * Lets templates reference entity FQCNs without `constant('App\\Entity\\Song')`.
 * Also exposes ENTITY_META => the registry, so templates can iterate.
 */
final class EntityGlobalsExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(private readonly EntityMetaRegistry $registry) {}

    public function getGlobals(): array
    {
        $globals = ['ENTITY_META' => $this->registry];
        $shortKeys = [];
        foreach ($this->registry->getAll() as $descriptor) {
            $shortKeys[$this->shortGlobalKey($descriptor->class)][] = $descriptor->class;
        }

        foreach ($this->registry->getAll() as $descriptor) {
            if ($descriptor->globalKey !== '') {
                $this->addEntityGlobals($globals, $descriptor->globalKey, $descriptor->class);
            }

            $shortKey = $this->shortGlobalKey($descriptor->class);
            if (count(array_unique($shortKeys[$shortKey] ?? [])) === 1) {
                $this->addEntityGlobals($globals, $shortKey, $descriptor->class);
            }
        }

        return $globals;
    }

    /**
     * @param array<string, mixed> $globals
     * @param class-string $class
     */
    private function addEntityGlobals(array &$globals, string $key, string $class): void
    {
        $globals[$key] = $class;
        foreach ((new \ReflectionClass($class))->getReflectionConstants(\ReflectionClassConstant::IS_PUBLIC) as $constant) {
            $globals[$key . '_' . $constant->getName()] = $constant->getValue();
        }
    }

    /** @param class-string $class */
    private function shortGlobalKey(string $class): string
    {
        $shortName = (new \ReflectionClass($class))->getShortName();

        return 'APP_ENTITY_' . strtoupper((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $shortName));
    }
}
