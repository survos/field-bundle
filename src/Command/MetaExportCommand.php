<?php

declare(strict_types=1);

namespace Survos\FieldBundle\Command;

use Survos\FieldBundle\Enum\Audience;
use Survos\FieldBundle\Enum\Purpose;
use Survos\FieldBundle\Model\EntityMetaDescriptor;
use Survos\FieldBundle\Model\RouteMetaDescriptor;
use Survos\FieldBundle\Registry\EntityMetaRegistry;
use Survos\FieldBundle\Registry\RouteMetaRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

#[AsCommand('meta:export', 'Dump the joined entity + route metadata graph (filterable) as JSON or YAML.')]
final class MetaExportCommand
{
    public function __construct(
        private readonly EntityMetaRegistry $entityRegistry,
        private readonly RouteMetaRegistry $routeRegistry,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('json|yaml')] string $format = 'json',
        #[Option('Write to file instead of stdout')] ?string $output = null,
        #[Option('Pretty-print JSON')] bool $pretty = false,
        #[Option('Comma-separated audiences to include (public,authenticated,admin,api,internal)')] ?string $audience = null,
        #[Option('Comma-separated purposes to include (show,list,dashboard,new,edit,delete,export,api,custom)')] ?string $purpose = null,
        #[Option('Comma-separated entity FQCNs to include')] ?string $entity = null,
        #[Option('Comma-separated tags; routes must carry at least one')] ?string $tag = null,
        #[Option('Comma-separated purposes; entities missing any are reported in gaps')] ?string $require = null,
        #[Option('Include entities that have zero matched routes')] bool $includeNonRoutedEntities = false,
    ): int {
        $audiences = self::parseList($audience);
        $purposes  = self::parseList($purpose);
        $entities  = self::parseList($entity);
        $tags      = self::parseList($tag);
        $required  = self::parseList($require);

        $routes     = $this->filterRoutes($audiences, $purposes, $entities, $tags);
        $entityRows = $this->buildEntities($routes, $entities, $includeNonRoutedEntities);
        $graph      = $this->buildGraph($routes);
        $gaps       = $required === [] ? [] : $this->buildGaps($entityRows, $graph, $required);

        $payload = [
            'meta' => [
                'generatedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                'filters' => array_filter([
                    'audience' => $audiences ?: null,
                    'purpose'  => $purposes  ?: null,
                    'entity'   => $entities  ?: null,
                    'tag'      => $tags      ?: null,
                    'require'  => $required  ?: null,
                ]),
                'counts' => [
                    'entities' => count($entityRows),
                    'routes'   => count($routes),
                    'gaps'     => count($gaps),
                ],
            ],
            'entities' => $entityRows,
            'routes'   => array_combine(
                array_map(static fn(RouteMetaDescriptor $d) => $d->name, $routes),
                array_map(self::serializeRoute(...), $routes),
            ),
            'graph' => $graph,
            'gaps'  => $gaps,
        ];

        $serialized = $this->serialize($payload, $format, $pretty);
        if ($serialized === null) {
            $io->error(sprintf('Unsupported format "%s" (expected json or yaml).', $format));
            return Command::INVALID;
        }

        if ($output !== null && $output !== '') {
            file_put_contents($output, $serialized);
            $io->success(sprintf(
                'Wrote %d entities, %d routes, %d gaps to %s',
                count($entityRows), count($routes), count($gaps), $output,
            ));
            return Command::SUCCESS;
        }

        $io->writeln($serialized);
        return Command::SUCCESS;
    }

    /**
     * @param list<string> $audiences
     * @param list<string> $purposes
     * @param list<string> $entities
     * @param list<string> $tags
     * @return list<RouteMetaDescriptor>
     */
    private function filterRoutes(array $audiences, array $purposes, array $entities, array $tags): array
    {
        $audienceCases = array_map(static fn(string $v) => Audience::tryFrom($v), $audiences);
        $purposeCases  = array_map(static fn(string $v) => Purpose::tryFrom($v), $purposes);

        return array_values(array_filter(
            $this->routeRegistry->all(),
            function (RouteMetaDescriptor $d) use ($audienceCases, $purposeCases, $entities, $tags) {
                if ($audienceCases !== [] && !\in_array($d->audience, $audienceCases, true)) return false;
                if ($purposeCases  !== [] && !\in_array($d->purpose,  $purposeCases,  true)) return false;
                if ($entities !== [] && ($d->entity === null || !\in_array($d->entity, $entities, true))) return false;
                if ($tags !== [] && array_intersect($tags, $d->tags) === []) return false;
                return true;
            },
        ));
    }

    /**
     * @param  list<RouteMetaDescriptor>  $routes
     * @param  list<string>               $explicitEntityFilter
     * @return array<class-string, array<string, mixed>>
     */
    private function buildEntities(array $routes, array $explicitEntityFilter, bool $includeNonRouted): array
    {
        $relevant = [];
        foreach ($routes as $r) {
            if ($r->entity !== null) {
                $relevant[$r->entity] = true;
            }
            foreach ($r->relatedEntities as $cls) {
                $relevant[$cls] = true;
            }
        }

        $rows = [];
        foreach ($this->entityRegistry->getAll() as $descriptor) {
            if ($explicitEntityFilter !== [] && !\in_array($descriptor->class, $explicitEntityFilter, true)) {
                continue;
            }
            if (!$includeNonRouted && !isset($relevant[$descriptor->class])) {
                continue;
            }
            $rows[$descriptor->class] = self::serializeEntity($descriptor);
        }
        return $rows;
    }

    /**
     * @param  list<RouteMetaDescriptor>
     * @return array<class-string, array<string, string>>
     */
    private function buildGraph(array $routes): array
    {
        $graph = [];
        foreach ($routes as $r) {
            if ($r->entity === null) continue;
            $graph[$r->entity][$r->purpose->value] = $r->name;
        }
        return $graph;
    }

    /**
     * @param  array<class-string, array<string, mixed>>  $entityRows
     * @param  array<class-string, array<string, string>> $graph
     * @param  list<string>                               $requiredPurposes
     * @return array<class-string, list<string>>
     */
    private function buildGaps(array $entityRows, array $graph, array $requiredPurposes): array
    {
        $gaps = [];
        foreach ($entityRows as $fqcn => $_meta) {
            $present = array_keys($graph[$fqcn] ?? []);
            $missing = array_values(array_diff($requiredPurposes, $present));
            if ($missing !== []) {
                $gaps[$fqcn] = $missing;
            }
        }
        return $gaps;
    }

    /** @return array<string, mixed> */
    private static function serializeRoute(RouteMetaDescriptor $d): array
    {
        return [
            'path'            => $d->path,
            'methods'         => $d->methods,
            'controller'      => $d->controller,
            'description'     => $d->description,
            'entity'          => $d->entity,
            'relatedEntities' => $d->relatedEntities,
            'params'          => (object) $d->params,
            'purpose'         => $d->purpose->value,
            'audience'        => $d->audience->value,
            'label'           => $d->label,
            'sitemap'         => $d->includeInSitemap(),
            'sitemapExplicit' => $d->sitemap,
            'changefreq'      => $d->changefreq,
            'priority'        => $d->priority,
            'tags'            => $d->tags,
            'parents'         => $d->parents,
        ];
    }

    /** @return array<string, mixed> */
    private static function serializeEntity(EntityMetaDescriptor $d): array
    {
        return [
            'shortName'      => $d->getShortName(),
            'label'          => $d->label,
            'group'          => $d->group,
            'order'          => $d->order,
            'icon'           => $d->icon,
            'iconClass'      => $d->iconClass,
            'description'    => $d->description,
            'hasApiResource' => $d->hasApiResource,
            'hasMeiliIndex'  => $d->hasMeiliIndex,
        ];
    }

    /** @return list<string> */
    private static function parseList(?string $csv): array
    {
        if ($csv === null || $csv === '') return [];
        return array_values(array_filter(array_map('trim', explode(',', $csv)), static fn($s) => $s !== ''));
    }

    /** @param array<string, mixed> $payload */
    private function serialize(array $payload, string $format, bool $pretty): ?string
    {
        return match (strtolower($format)) {
            'json'        => json_encode($payload, ($pretty ? \JSON_PRETTY_PRINT : 0) | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) ?: null,
            'yaml', 'yml' => Yaml::dump($payload, 6, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_OBJECT_AS_MAP),
            default       => null,
        };
    }
}
