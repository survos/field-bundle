<?php

declare(strict_types=1);

namespace Survos\FieldBundle\Service;

use Survos\FieldBundle\Model\RouteMetaDescriptor;
use Survos\FieldBundle\Registry\RouteMetaRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Builds the markdown route outline -- shared by RouteSitemapCommand (CLI)
 * and FieldReportController's web page (Fields nav > Route Sitemap), so
 * there's exactly one place that knows how to render this.
 */
final class RouteSitemapBuilder
{
    public function __construct(
        private readonly RouteMetaRegistry $routeRegistry,
        #[Autowire('%field.all_routes%')] private readonly array $allRoutes = [],
    ) {
    }

    public function render(bool $includeGaps = true): string
    {
        $lines = [];
        $lines[] = '# Route Sitemap';
        $lines[] = '';
        $lines[] = sprintf(
            '%d of %d routes carry `#[RouteMeta]` (%d%% coverage).',
            \count($this->routeRegistry->all()),
            \count($this->allRoutes),
            $this->allRoutes === [] ? 0 : (int) round(100 * \count($this->routeRegistry->all()) / \count($this->allRoutes)),
        );
        $lines[] = '';

        $byEntity = [];
        $appLevel = [];
        foreach ($this->routeRegistry->all() as $d) {
            if ($d->entity !== null) {
                $byEntity[$d->entity][] = $d;
            } else {
                $appLevel[] = $d;
            }
        }
        ksort($byEntity);

        if ($appLevel !== []) {
            $lines[] = '## App-level pages';
            $lines[] = '';
            foreach (self::sortByParentsThenName($appLevel) as $d) {
                $lines[] = self::renderRoute($d);
            }
            $lines[] = '';
        }

        foreach ($byEntity as $entityClass => $routes) {
            $short = substr((string) strrchr($entityClass, '\\'), 1) ?: $entityClass;
            $lines[] = "## {$short}";
            $lines[] = '';
            foreach (self::sortByPurpose($routes) as $d) {
                $lines[] = self::renderRoute($d);
            }
            $lines[] = '';
        }

        if ($includeGaps) {
            $missing = array_values(array_filter($this->allRoutes, static fn(array $r) => !$r['hasMeta']));
            $lines[] = sprintf('## ⚠ Missing `#[RouteMeta]` (%d)', \count($missing));
            $lines[] = '';
            if ($missing === []) {
                $lines[] = 'None — full coverage.';
            } else {
                $byController = [];
                foreach ($missing as $r) {
                    $byController[$r['controller']][] = $r;
                }
                ksort($byController);
                foreach ($byController as $controller => $routes) {
                    $lines[] = "**{$controller}**";
                    foreach ($routes as $r) {
                        $lines[] = "- `{$r['name']}` — `{$r['path']}`";
                    }
                }
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /** @return array{covered: int, total: int, percent: int} */
    public function coverage(): array
    {
        $covered = \count($this->routeRegistry->all());
        $total = \count($this->allRoutes);

        return [
            'covered' => $covered,
            'total'   => $total,
            'percent' => $total === 0 ? 0 : (int) round(100 * $covered / $total),
        ];
    }

    private static function renderRoute(RouteMetaDescriptor $d): string
    {
        $audience = strtoupper($d->audience->value);
        $methods  = implode('|', $d->methods) ?: 'GET';

        return sprintf(
            "- **%s** `%s %s` [%s] — %s",
            $d->label ?? $d->name,
            $methods,
            $d->path,
            $audience,
            $d->description,
        );
    }

    /** @param list<RouteMetaDescriptor> $routes @return list<RouteMetaDescriptor> */
    private static function sortByParentsThenName(array $routes): array
    {
        usort($routes, static fn(RouteMetaDescriptor $a, RouteMetaDescriptor $b) =>
            (\count($a->parents) <=> \count($b->parents)) ?: ($a->name <=> $b->name));
        return $routes;
    }

    /** @param list<RouteMetaDescriptor> $routes @return list<RouteMetaDescriptor> */
    private static function sortByPurpose(array $routes): array
    {
        $order = ['dashboard' => 0, 'list' => 1, 'show' => 2, 'new' => 3, 'edit' => 4, 'delete' => 5, 'export' => 6, 'api' => 7, 'custom' => 8];
        usort($routes, static fn(RouteMetaDescriptor $a, RouteMetaDescriptor $b) =>
            ($order[$a->purpose->value] ?? 9) <=> ($order[$b->purpose->value] ?? 9));
        return $routes;
    }
}
