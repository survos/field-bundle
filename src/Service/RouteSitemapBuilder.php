<?php

declare(strict_types=1);

namespace Survos\FieldBundle\Service;

use Survos\FieldBundle\Registry\RouteMetaRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Builds the route outline (markdown for CLI/agent consumption, structured
 * array for the JSON endpoint and the HTML report) -- shared by
 * RouteSitemapCommand and FieldReportController, so there's exactly one
 * place that knows how to assemble this.
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

    /**
     * @return array{
     *     coverage: array{covered: int, total: int, percent: int},
     *     controllers: list<array{
     *         class: string, shortName: string, prefix: ?string,
     *         routes: list<array{
     *             name: string, path: string, hasMeta: bool,
     *             methods: list<string>, description: ?string, audience: ?string,
     *             purpose: ?string, label: ?string, entity: ?string, tags: list<string>,
     *         }>,
     *     }>,
     * }
     */
    public function toArray(bool $includeGaps = true): array
    {
        $byName = [];
        foreach ($this->routeRegistry->all() as $d) {
            $byName[$d->name] = $d;
        }

        $byController = [];
        foreach ($this->allRoutes as $r) {
            if (!$includeGaps && !$r['hasMeta']) {
                continue;
            }

            $meta = $byName[$r['name']] ?? null;
            $byController[$r['controllerClass']][] = [
                'name'        => $r['name'],
                'path'        => $r['path'],
                'hasMeta'     => $r['hasMeta'],
                'methods'     => $meta?->methods ?? [],
                'description' => $meta?->description,
                'audience'    => $meta?->audience->value,
                'purpose'     => $meta?->purpose->value,
                'label'       => $meta?->label,
                'entity'      => $meta?->entity,
                'tags'        => $meta?->tags ?? [],
            ];
        }
        ksort($byController);

        $controllers = [];
        foreach ($byController as $controllerClass => $routes) {
            usort($routes, static fn(array $a, array $b) => $a['name'] <=> $b['name']);
            $controllers[] = [
                'class'     => $controllerClass,
                'shortName' => substr((string) strrchr($controllerClass, '\\'), 1) ?: $controllerClass,
                'prefix'    => $this->controllerPrefixes[$controllerClass] ?? null,
                'routes'    => $routes,
            ];
        }

        return [
            'coverage'    => $this->coverage(),
            'controllers' => $controllers,
        ];
    }

    public function render(bool $includeGaps = true): string
    {
        $data = $this->toArray($includeGaps);

        $lines = [];
        $lines[] = '# Route Sitemap';
        $lines[] = '';
        $lines[] = sprintf(
            '%d of %d routes carry `#[RouteMeta]` (%d%%), across %d controller(s).',
            $data['coverage']['covered'],
            $data['coverage']['total'],
            $data['coverage']['percent'],
            \count($data['controllers']),
        );
        $lines[] = '';

        foreach ($data['controllers'] as $c) {
            $heading = $c['prefix'] !== null ? "{$c['shortName']} (`{$c['prefix']}`)" : $c['shortName'];
            $lines[] = "## {$heading}";
            $lines[] = '';

            foreach ($c['routes'] as $r) {
                $lines[] = $r['hasMeta']
                    ? self::renderRouteLine($r)
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

    /** @param array<string, mixed> $r */
    private static function renderRouteLine(array $r): string
    {
        $audience = strtoupper((string) $r['audience']);
        $methods  = implode('|', $r['methods']) ?: 'GET';

        return sprintf(
            "- **%s** `%s %s` [%s] — %s",
            $r['label'] ?? $r['name'],
            $methods,
            $r['path'],
            $audience,
            $r['description'],
        );
    }
}
