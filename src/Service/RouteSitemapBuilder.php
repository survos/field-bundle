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
 *
 * Grouped by controller class (not by entity) -- an agent or dev exploring
 * an unfamiliar app thinks "what does AccController do", not "what routes
 * touch the Acc entity", and grouping by controller means covered and
 * not-yet-covered routes for the same class show up together instead of
 * being split across two disconnected sections.
 */
final class RouteSitemapBuilder
{
    public function __construct(
        private readonly RouteMetaRegistry $routeRegistry,
        #[Autowire('%field.all_routes%')] private readonly array $allRoutes = [],
        #[Autowire('%field.controller_prefixes%')] private readonly array $controllerPrefixes = [],
    ) {
    }

    public function render(bool $includeGaps = true): string
    {
        $byName = [];
        foreach ($this->routeRegistry->all() as $d) {
            $byName[$d->name] = $d;
        }

        // Group every route (covered or not) by controller class, using
        // $allRoutes as the authoritative full list and $byName to enrich
        // covered ones with their #[RouteMeta] payload.
        $byController = [];
        foreach ($this->allRoutes as $r) {
            if (!$includeGaps && !$r['hasMeta']) {
                continue;
            }
            $byController[$r['controllerClass']][] = [
                'name'     => $r['name'],
                'path'     => $r['path'],
                'meta'     => $byName[$r['name']] ?? null,
            ];
        }
        ksort($byController);

        $lines = [];
        $lines[] = '# Route Sitemap';
        $lines[] = '';
        $lines[] = sprintf(
            '%d of %d routes carry `#[RouteMeta]` (%d%%), across %d controller(s).',
            \count($this->routeRegistry->all()),
            \count($this->allRoutes),
            $this->coverage()['percent'],
            \count($byController),
        );
        $lines[] = '';

        foreach ($byController as $controllerClass => $routes) {
            $short = substr((string) strrchr($controllerClass, '\\'), 1) ?: $controllerClass;
            $prefix = $this->controllerPrefixes[$controllerClass] ?? null;
            $heading = $prefix !== null ? "{$short} (`{$prefix}`)" : $short;

            $lines[] = "## {$heading}";
            $lines[] = '';

            usort($routes, static fn(array $a, array $b) => $a['name'] <=> $b['name']);
            foreach ($routes as $r) {
                $lines[] = $r['meta'] !== null
                    ? self::renderRoute($r['meta'])
                    : sprintf('- ⚠ **%s** `%s` — _missing `#[RouteMeta]`_', $r['name'], $r['path']);
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
}
