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
        foreach ($this->registry->getAll() as $descriptor) {
            if ($descriptor->globalKey !== '') {
                $globals[$descriptor->globalKey] = $descriptor->class;
            }
        }

        return $globals;
    }
}
