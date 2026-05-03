<?php

declare(strict_types=1);

namespace Survos\FieldBundle\Service;

use Survos\FieldBundle\Attribute\RouteIdentity;

/**
 * Resolves URL identity parameters for an entity by reading its
 * #[RouteIdentity] attribute and walking the declared parent chain.
 *
 * Pure static utility — used both by RouteIdentityTrait (called from inside
 * an entity instance) and by external consumers (crawler, sitemap, link
 * helpers) that want the same answer without going through the trait.
 *
 * Caches the resolved attribute per class. Reflection is cheap but not
 * free, and `getRp()` is called heavily in menu rendering.
 */
final class RouteIdentityResolver
{
    /** @var array<class-string, ?RouteIdentity> */
    private static array $cache = [];

    /**
     * Build the URL identity map for $entity, walking parents first.
     *
     * Result preserves order: parent chain first, then this entity's own
     * key. Extras are merged last, so callers can override any key.
     *
     * @param  array<string, mixed> $extras
     * @return array<string, mixed>
     */
    public static function paramsFor(object $entity, array $extras = []): array
    {
        $identity = self::lookup($entity::class);
        if ($identity === null) {
            return self::legacyFallback($entity, $extras);
        }

        $params = [];

        foreach ($identity->parents as $parentProp) {
            $parent = self::readParent($entity, $parentProp);
            if ($parent !== null) {
                $params = array_merge($params, self::paramsFor($parent));
            }
        }

        $params[self::keyFor($entity, $identity)] = self::readField($entity, $identity->field);

        return array_merge($params, $extras);
    }

    /**
     * Encode an entity's identity chain into a single string — the
     * replacement for the legacy `erp()` hack used by tools (EasyAdmin,
     * older grids) that can't pass compound keys.
     */
    public static function encode(object $entity, string $separator = '/'): string
    {
        return implode($separator, array_map(
            static fn ($v): string => (string) $v,
            self::paramsFor($entity),
        ));
    }

    /** Look up #[RouteIdentity] for a class, walking the inheritance chain. */
    public static function lookup(string $class): ?RouteIdentity
    {
        if (\array_key_exists($class, self::$cache)) {
            return self::$cache[$class];
        }

        try {
            $rc = new \ReflectionClass($class);
        } catch (\ReflectionException) {
            return self::$cache[$class] = null;
        }

        for ($cursor = $rc; $cursor !== false; $cursor = $cursor->getParentClass()) {
            $attrs = $cursor->getAttributes(RouteIdentity::class);
            if ($attrs !== []) {
                return self::$cache[$class] = $attrs[0]->newInstance();
            }
        }

        return self::$cache[$class] = null;
    }

    private static function keyFor(object $entity, RouteIdentity $identity): string
    {
        if ($identity->key !== null) {
            return $identity->key;
        }
        $short = (new \ReflectionClass($entity))->getShortName();
        return lcfirst($short) . 'Id';
    }

    private static function readField(object $entity, string $field): mixed
    {
        $rc = new \ReflectionClass($entity);
        if ($rc->hasProperty($field) && $rc->getProperty($field)->isPublic()) {
            return $entity->{$field};
        }
        $getter = 'get' . ucfirst($field);
        if (method_exists($entity, $getter)) {
            return $entity->{$getter}();
        }
        if (method_exists($entity, $field)) {
            return $entity->{$field}();
        }
        throw new \LogicException(sprintf(
            '#[RouteIdentity] on %s declares field "%s" but neither $%s nor %s() is accessible.',
            $entity::class, $field, $field, $getter,
        ));
    }

    private static function readParent(object $entity, string $prop): ?object
    {
        $rc = new \ReflectionClass($entity);
        if ($rc->hasProperty($prop) && $rc->getProperty($prop)->isPublic()) {
            $value = $entity->{$prop};
            return \is_object($value) ? $value : null;
        }
        $getter = 'get' . ucfirst($prop);
        if (method_exists($entity, $getter)) {
            $value = $entity->{$getter}();
            return \is_object($value) ? $value : null;
        }
        return null;
    }

    /**
     * Backward-compatible behavior for entities that haven't migrated yet:
     * if the entity is a RouteParametersInterface, use its getRp(); else
     * fall back to {lcShortName}Id => getId(). This lets the resolver be
     * called on legacy entities without crashing.
     *
     * @param  array<string, mixed> $extras
     * @return array<string, mixed>
     */
    private static function legacyFallback(object $entity, array $extras): array
    {
        if (\interface_exists(\Survos\CoreBundle\Entity\RouteParametersInterface::class)
            && $entity instanceof \Survos\CoreBundle\Entity\RouteParametersInterface
        ) {
            return $entity->getRp($extras);
        }

        if (method_exists($entity, 'getId')) {
            $short = (new \ReflectionClass($entity))->getShortName();
            return array_merge([lcfirst($short) . 'Id' => $entity->getId()], $extras);
        }

        throw new \LogicException(sprintf(
            'No #[RouteIdentity] on %s and no getId() to fall back on. Add the attribute or implement RouteParametersInterface.',
            $entity::class,
        ));
    }
}
