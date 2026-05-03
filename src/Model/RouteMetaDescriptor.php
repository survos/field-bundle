<?php

declare(strict_types=1);

namespace Survos\FieldBundle\Model;

use Survos\FieldBundle\Enum\Audience;
use Survos\FieldBundle\Enum\Purpose;

/**
 * Compiled metadata for one routed controller action, produced by RouteMetaPass.
 * Joins atlas-bundle's RouteEntry with the matching #[RouteMeta] payload.
 */
final class RouteMetaDescriptor
{
    /**
     * @param list<string>                $methods
     * @param list<class-string>          $relatedEntities
     * @param array<string, class-string> $params
     * @param list<string>                $tags
     * @param list<string>                $parents
     */
    public function __construct(
        public readonly string   $name,
        public readonly string   $path,
        public readonly array    $methods,
        public readonly string   $controller,
        public readonly string   $description,
        public readonly ?string  $entity          = null,
        public readonly array    $relatedEntities = [],
        public readonly array    $params          = [],
        public readonly Purpose  $purpose         = Purpose::Custom,
        public readonly ?string  $label           = null,
        public readonly Audience $audience        = Audience::Authenticated,
        public readonly ?bool    $sitemap         = null,
        public readonly ?string  $changefreq      = null,
        public readonly ?float   $priority        = null,
        public readonly array    $tags            = [],
        public readonly array    $parents         = [],
    ) {}

    /**
     * Effective sitemap inclusion: explicit value if set, else default by audience.
     */
    public function includeInSitemap(): bool
    {
        return $this->sitemap ?? ($this->audience === Audience::Public);
    }
}
