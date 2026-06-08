<?php

declare(strict_types=1);

namespace Survos\FieldBundle\Controller;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use Survos\FieldBundle\Registry\EntityMetaRegistry;
use Survos\FieldBundle\Service\FieldReader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\RouterInterface;

/**
 * Per-entity admin dashboard.
 *
 * The `$code` route param is the snake-cased identity computed at compile
 * time on EntityMetaDescriptor, e.g. "app_bill".
 */
final class EntityDashboardController extends AbstractController
{
    public function __construct(
        private readonly EntityMetaRegistry $registry,
        private readonly FieldReader $fieldReader,
        private readonly ?RouterInterface $router = null,
        private readonly ?ManagerRegistry $doctrine = null,
        private readonly ?object $meiliRegistry = null,
        private readonly ?object $chatWorkspaceResolver = null,
        private readonly ?object $uxSearchRegistry = null,
        private readonly ?object $workflowHelper = null,
    ) {}

    #[Route('/entity/{code}', name: 'survos_entity_dashboard', methods: ['GET'])]
    public function dashboard(string $code): Response
    {
        $descriptor = $this->registry->getByCode($code);
        if (!$descriptor) {
            throw $this->createNotFoundException(sprintf('No #[EntityMeta] entity registered for code "%s".', $code));
        }

        $class = $descriptor->class;

        return $this->render('@SurvosField/entity/dashboard.html.twig', [
            'descriptor' => $descriptor,
            'rowCount' => $this->resolveRowCount($class),
            'doctrine' => $this->resolveDoctrine($class),
            'fields' => $this->fieldReader->getDescriptors($class),
            'constants' => $this->resolveConstants($class),
            'meili' => $this->resolveMeili($class),
            'meiliRegistryAvailable' => $this->meiliRegistry !== null,
            'uxSearch' => $this->resolveUxSearch($class, $descriptor->code),
            'uxSearchRegistryAvailable' => $this->uxSearchRegistry !== null,
            'workflows' => $this->resolveWorkflows($class),
            'workflowRegistryAvailable' => $this->workflowHelper !== null,
            'browseUrl' => $this->resolveBrowseUrl($code),
            'constantsUrl' => $this->routeUrl('survos_entity_constants', []),
        ]);
    }

    #[Route('/entity-constants', name: 'survos_entity_constants', methods: ['GET'])]
    public function constants(): Response
    {
        $shortKeys = [];
        foreach ($this->registry->getAll() as $descriptor) {
            $shortKeys[$this->shortGlobalKey($descriptor->class)][] = $descriptor->class;
        }

        $rows = [];
        foreach ($this->registry->getAll() as $descriptor) {
            $keys = [];
            if ($descriptor->globalKey !== '') {
                $keys[] = [
                    'name' => $descriptor->globalKey,
                    'type' => 'class',
                    'value' => $descriptor->class,
                ];
            }

            $shortKey = $this->shortGlobalKey($descriptor->class);
            if (count(array_unique($shortKeys[$shortKey] ?? [])) === 1 && $shortKey !== $descriptor->globalKey) {
                $keys[] = [
                    'name' => $shortKey,
                    'type' => 'class alias',
                    'value' => $descriptor->class,
                ];
            }

            foreach ((new \ReflectionClass($descriptor->class))->getReflectionConstants(\ReflectionClassConstant::IS_PUBLIC) as $constant) {
                foreach ($keys as $key) {
                    $rows[] = [
                        'name' => $key['name'] . '_' . $constant->getName(),
                        'type' => 'constant',
                        'value' => $constant->getValue(),
                        'descriptor' => $descriptor,
                    ];
                }
            }

            foreach ($keys as $key) {
                $rows[] = [
                    'name' => $key['name'],
                    'type' => $key['type'],
                    'value' => $key['value'],
                    'descriptor' => $descriptor,
                ];
            }
        }

        usort($rows, static fn (array $a, array $b): int => $a['name'] <=> $b['name']);

        return $this->render('@SurvosField/entity/constants.html.twig', [
            'rows' => $rows,
        ]);
    }

    private function resolveRowCount(string $class): ?int
    {
        if (!$this->doctrine) {
            return null;
        }
        $em = $this->doctrine->getManagerForClass($class);
        if (!$em) {
            return null;
        }
        try {
            return (int) $em->getRepository($class)->count([]);
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveDoctrine(string $class): ?array
    {
        if (!$this->doctrine || !$em = $this->doctrine->getManagerForClass($class)) {
            return null;
        }

        try {
            /** @var ClassMetadata $metadata */
            $metadata = $em->getClassMetadata($class);
            $fields = [];
            foreach ($metadata->getFieldNames() as $name) {
                $mapping = $metadata->getFieldMapping($name);
                $fields[$name] = [
                    'name' => $name,
                    'column' => $mapping->columnName ?? $name,
                    'type' => $mapping->type ?? 'string',
                    'nullable' => (bool) ($mapping->nullable ?? false),
                    'length' => $mapping->length ?? null,
                    'id' => $metadata->isIdentifier($name),
                ];
            }

            return [
                'table' => $metadata->getTableName(),
                'identifier' => $metadata->getIdentifierFieldNames(),
                'fields' => $fields,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveConstants(string $class): array
    {
        $constants = [];
        foreach ((new \ReflectionClass($class))->getReflectionConstants(\ReflectionClassConstant::IS_PUBLIC) as $constant) {
            $value = $constant->getValue();
            $constants[] = [
                'name' => $constant->getName(),
                'type' => get_debug_type($value),
                'count' => is_countable($value) ? count($value) : null,
                'value' => $value,
            ];
        }

        return $constants;
    }

    private function resolveMeili(string $class): ?array
    {
        if (!$this->meiliRegistry) {
            return null;
        }

        if (method_exists($this->meiliRegistry, 'names') && method_exists($this->meiliRegistry, 'classFor')) {
            foreach ($this->meiliRegistry->names() as $baseName) {
                if ($this->meiliRegistry->classFor((string) $baseName) === $class) {
                    return $this->meiliView((string) $baseName);
                }
            }
        }

        return null;
    }

    private function meiliView(string $baseName): array
    {
        $settings = method_exists($this->meiliRegistry, 'settingsFor')
            ? ($this->meiliRegistry->settingsFor($baseName) ?? [])
            : [];
        $uid = method_exists($this->meiliRegistry, 'uidFor')
            ? (string) $this->meiliRegistry->uidFor($baseName)
            : $baseName;

        return [
            'baseName' => $baseName,
            'uid' => $uid,
            'primaryKey' => (string) ($settings['primaryKey'] ?? ''),
            'facets' => (array) ($settings['facets'] ?? []),
            'filterable' => (array) ($settings['filterableAttributes'] ?? $settings['filterable'] ?? []),
            'sortable' => (array) ($settings['sortableAttributes'] ?? $settings['sortable'] ?? []),
            'searchable' => (array) ($settings['searchableAttributes'] ?? $settings['searchable'] ?? []),
            'searchUrl' => $this->routeUrl('meili_insta', ['indexName' => $baseName]),
            'dashboardUrl' => $this->routeUrl('meili_admin_meili_index_dashboard', ['indexName' => $baseName]),
            'registryUrl' => $this->routeUrl('meili_registry', []),
            'chatUrls' => $this->chatUrls($baseName),
        ];
    }

    private function chatUrls(string $baseName): array
    {
        if (!$this->chatWorkspaceResolver || !method_exists($this->chatWorkspaceResolver, 'workspaceTemplatesForIndex')) {
            return [];
        }

        $urls = [];
        foreach ($this->chatWorkspaceResolver->workspaceTemplatesForIndex($baseName) as $workspace) {
            $workspace = (string) $workspace;
            $url = $this->routeUrl('meili_chat', [
                'indexName' => $baseName,
                'workspace' => $workspace,
            ]);
            if ($url !== null) {
                $urls[] = ['workspace' => $workspace, 'url' => $url];
            }
        }

        return $urls;
    }

    private function resolveUxSearch(string $class, string $code): ?array
    {
        if (!$this->uxSearchRegistry || !method_exists($this->uxSearchRegistry, 'forClass')) {
            return null;
        }

        $search = $this->uxSearchRegistry->forClass($class);
        if (!$search) {
            return null;
        }

        return [
            'name' => $search->name ?? $code,
            'url' => $this->routeUrl('survos_entity_ux_search', ['code' => $code]),
            'hitTemplate' => $search->hitTemplate ?? null,
        ];
    }

    private function resolveWorkflows(string $class): array
    {
        if (!$this->workflowHelper || !method_exists($this->workflowHelper, 'getWorkflowsGroupedByClass')) {
            return [];
        }

        $grouped = $this->workflowHelper->getWorkflowsGroupedByClass();
        $names = $grouped[$class] ?? [];
        foreach ($grouped as $supportedClass => $workflowNames) {
            if ($supportedClass !== $class && is_a($class, (string) $supportedClass, true)) {
                $names = array_merge($names, (array) $workflowNames);
            }
        }

        return array_map(fn (string $name): array => [
            'name' => $name,
            'url' => $this->routeUrl('survos_workflow', ['flowCode' => $name]),
        ], array_values(array_unique($names)));
    }

    private function resolveBrowseUrl(string $code): ?string
    {
        return $this->routeUrl('survos_admin_browse', ['code' => $code]);
    }

    private function routeUrl(string $route, array $parameters): ?string
    {
        if (!$this->router || !$this->router->getRouteCollection()->get($route)) {
            return null;
        }

        try {
            return $this->router->generate($route, $parameters);
        } catch (\Throwable) {
            return null;
        }
    }

    private function shortGlobalKey(string $class): string
    {
        $shortName = (new \ReflectionClass($class))->getShortName();

        return 'APP_ENTITY_' . strtoupper((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $shortName));
    }
}
