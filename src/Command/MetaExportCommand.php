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
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

/**
 * Joins EntityMetaRegistry + RouteMetaRegistry into a single JSON/YAML payload:
 *
 *   - entities     : every #[EntityMeta]-annotated class
 *   - routes       : every #[RouteMeta]-annotated controller method (filtered)
 *   - graph        : entity FQCN -> { purpose -> route_name }, the question
 *                    "what's the show route for Tenant?" answered in O(1)
 *   - gaps         : entities missing a configurable set of canonical purposes
 *
 * The output is the "paste-into-Claude" artifact: one file that summarizes
 * the app's controller surface area for design-question consumption. It's
 * also the input the future crawler-bundle reads to plan its visit list.
 *
 * Filters compose with AND: --audience=public --purpose=show would emit only
 * the routes that are BOTH public AND show-purpose.
 */
#[AsCommand(
    name: 'meta:export',
    description: 'Dump the joined entity + route metadata graph (filterable) as JSON or YAML.',
)]
final class MetaExportCommand extends Command
{
    public function __construct(
        private readonly EntityMetaRegistry $entityRegistry,
        private readonly RouteMetaRegistry $routeRegistry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('format',   'f',  InputOption::VALUE_REQUIRED, 'json|yaml', 'json')
            ->addOption('output',   'o',  InputOption::VALUE_REQUIRED, 'Write to file instead of stdout')
            ->addOption('pretty',   null, InputOption::VALUE_NONE,     'Pretty-print JSON')
            ->addOption('audience', null, InputOption::VALUE_REQUIRED, 'Comma-separated audiences to include (public,authenticated,admin,api,internal)')
            ->addOption('purpose',  null, InputOption::VALUE_REQUIRED, 'Comma-separated purposes to include (show,list,dashboard,new,edit,delete,export,api,custom)')
            ->addOption('entity',   null, InputOption::VALUE_REQUIRED, 'Comma-separated entity FQCNs to include (matches RouteMeta.entity)')
            ->addOption('tag',      null, InputOption::VALUE_REQUIRED, 'Comma-separated tags; routes must carry at least one')
            ->addOption('require',  null, InputOption::VALUE_REQUIRED, 'Comma-separated purposes; entities missing any are reported in `gaps` (e.g. show,edit)')
            ->addOption('include-non-routed-entities', null, InputOption::VALUE_NONE, 'Include #[EntityMeta] entities that have zero matched routes (default: hide)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $audiences = self::parseList($input->getOption('audience'));
        $purposes  = self::parseList($input->getOption('purpose'));
        $entities  = self::parseList($input->getOption('entity'));
        $tags      = self::parseList($input->getOption('tag'));
        $required  = self::parseList($input->getOption('require'));

        $routes      = $this->filterRoutes($audiences, $purposes, $entities, $tags);
        $entityRows  = $this->buildEntities($routes, $entities, (bool) $input->getOption('include-non-routed-entities'));
        $graph       = $this->buildGraph($routes);
        $gaps        = $required === [] ? [] : $this->buildGaps($entityRows, $graph, $required);

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
                array_map(static fn (RouteMetaDescriptor $d) => $d->name, $routes),
                array_map(self::serializeRoute(...), $routes),
            ),
            'graph' => $graph,
            'gaps'  => $gaps,
        ];

        $serialized = $this->serialize($payload, (string) $input->getOption('format'), (bool) $input->getOption('pretty'));
        if ($serialized === null) {
            $io->error(sprintf('Unsupported format "%s" (expected json or yaml).', $input->getOption('format')));
            return Command::INVALID;
        }

        $target = $input->getOption('output');
        if (\is_string($target) && $target !== '') {
            file_put_contents($target, $serialized);
            $io->success(sprintf(
                'Wrote %d entities, %d routes, %d gaps to %s',
                count($entityRows), count($routes), count($gaps), $target,
            ));
            return Command::SUCCESS;
        }

        $output->writeln($serialized);
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
        $audienceCases = array_map(static fn (string $v) => Audience::tryFrom($v), $audiences);
        $purposeCases  = array_map(static fn (string $v) => Purpose::tryFrom($v),  $purposes);

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
     * @param  list<RouteMetaDescriptor> $routes
     * @param  list<string>              $explicitEntityFilter
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
     * @param  list<RouteMetaDescriptor> $routes
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
     * @param  array<class-string, array<string, mixed>> $entityRows
     * @param  array<class-string, array<string, string>> $graph
     * @param  list<string>                              $requiredPurposes
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

    /**
     * @return array<string, mixed>
     */
    private static function serializeRoute(RouteMetaDescriptor $d): array
    {
        return [
            'path'             => $d->path,
            'methods'          => $d->methods,
            'controller'       => $d->controller,
            'description'     => $d->description,
            'entity'           => $d->entity,
            'relatedEntities'  => $d->relatedEntities,
            'params'           => (object) $d->params,
            'purpose'          => $d->purpose->value,
            'audience'         => $d->audience->value,
            'label'            => $d->label,
            'sitemap'          => $d->includeInSitemap(),
            'sitemapExplicit'  => $d->sitemap,
            'changefreq'       => $d->changefreq,
            'priority'         => $d->priority,
            'tags'             => $d->tags,
            'parents'          => $d->parents,
        ];
    }

    /**
     * @return array<string, mixed>
     */
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

    /**
     * @return list<string>
     */
    private static function parseList(?string $csv): array
    {
        if ($csv === null || $csv === '') return [];
        return array_values(array_filter(array_map('trim', explode(',', $csv)), static fn ($s) => $s !== ''));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function serialize(array $payload, string $format, bool $pretty): ?string
    {
        return match (strtolower($format)) {
            'json'        => json_encode($payload, ($pretty ? \JSON_PRETTY_PRINT : 0) | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) ?: null,
            'yaml', 'yml' => Yaml::dump($payload, 6, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_OBJECT_AS_MAP),
            default       => null,
        };
    }
}
