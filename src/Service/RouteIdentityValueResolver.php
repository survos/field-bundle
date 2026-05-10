<?php

declare(strict_types=1);

namespace Survos\FieldBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\Mapping\MappingException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Resolves a typed entity controller argument by reading the entity's
 * `#[RouteIdentity]` attribute and looking the entity up by its declared
 * field — closing the loop so `RouteIdentity` drives both URL generation
 * (via `getRp()` / {@see RouteIdentityResolver}) and URL resolution.
 *
 * Replaces the boilerplate `#[MapEntity(mapping: ['xId' => 'code'])]` that
 * had to be repeated on every controller across every Survos project.
 *
 * Auto-tagged by Symfony's `autoconfigure()` as
 * `controller.argument_value_resolver`.
 *
 * Behaviour:
 *   - If the argument's type isn't a managed Doctrine entity → defer.
 *   - If the entity has no `#[RouteIdentity]` → defer.
 *   - If the request has no value for the declared key → defer.
 *   - Otherwise: `findOneBy([$identity->field => $value])`. Throws
 *     `NotFoundHttpException` on miss for non-nullable arguments.
 *
 * @todo Compound-key lookups. The legacy `UNIQUE_PARAMETERS` const +
 *       `RouteParametersTrait` pattern supported parent chains
 *       (`state`+`city`, `tenant`+`collection`) — when
 *       `RouteIdentity::$parents` is non-empty, walk the parent chain
 *       (resolve each parent first, then constrain the child by
 *       `field` + parent association). Until that lands here, controllers
 *       that need compound resolution should fall back to `#[MapEntity]`.
 */
final class RouteIdentityValueResolver implements ValueResolverInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return iterable<int, object|null>
     */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        $class = $argument->getType();
        if ($class === null || !class_exists($class)) {
            return [];
        }

        // Only handle Doctrine-managed entities.
        try {
            $this->em->getClassMetadata($class);
        } catch (MappingException) {
            return [];
        } catch (\Throwable) {
            return [];
        }

        $identity = RouteIdentityResolver::lookup($class);
        if ($identity === null) {
            return [];
        }

        // Compound chain (parents) — defer to MapEntity for now (see @todo).
        if ($identity->parents !== []) {
            return [];
        }

        $key = $identity->key
            ?? lcfirst((new \ReflectionClass($class))->getShortName()) . 'Id';

        $value = $request->attributes->get($key);
        if ($value === null || $value === '') {
            return [];
        }

        $entity = $this->em->getRepository($class)->findOneBy([$identity->field => $value]);
        if ($entity === null) {
            if (!$argument->isNullable()) {
                throw new NotFoundHttpException(sprintf(
                    '%s not found by %s=%s',
                    (new \ReflectionClass($class))->getShortName(),
                    $identity->field,
                    is_scalar($value) ? (string) $value : '<non-scalar>',
                ));
            }
            return [null];
        }

        return [$entity];
    }
}
