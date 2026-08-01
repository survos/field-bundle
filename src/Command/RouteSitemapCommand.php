<?php

declare(strict_types=1);

namespace Survos\FieldBundle\Command;

use Survos\FieldBundle\Model\RouteMetaDescriptor;
use Survos\FieldBundle\Registry\RouteMetaRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Human-readable outline of every route: what it does, who it's for --
 * "kinda like a sitemap.xml file" but for reading, not crawling. Grouped by
 * entity (falling back to #[RouteMeta]'s $parents for app-level pages),
 * annotated routes first, then a coverage-gap list.
 *
 * meta:export (MetaExportCommand) already dumps the same underlying data as
 * machine-readable JSON/YAML for tooling; this command is the human-facing
 * sibling -- a markdown tree you'd actually read top to bottom to understand
 * how an app's pages relate, plus (the part meta:export can't do, since
 * RouteMetaRegistry only ever sees #[RouteMeta]-covered routes) the list of
 * every route that has NO metadata yet, so you know exactly what's left to
 * annotate.
 */
#[AsCommand('field:routes:sitemap', 'Render a human-readable outline of every route (description, audience, hierarchy) plus a coverage-gap list.')]
final class RouteSitemapCommand
{
    public function __construct(
        private readonly RouteMetaRegistry $routeRegistry,
        #[Autowire('%field.all_routes%')] private readonly array $allRoutes = [],
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Write markdown to a file instead of stdout')] ?string $output = null,
        #[Option('Skip the coverage-gap section')] bool $noGaps = false,
    ): int {
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

        if (!$noGaps) {
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

        $markdown = implode("\n", $lines);

        if ($output !== null && $output !== '') {
            file_put_contents($output, $markdown);
            $io->success("Wrote sitemap to {$output}");
            return Command::SUCCESS;
        }

        $io->writeln($markdown);
        return Command::SUCCESS;
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
