<?php

declare(strict_types=1);

namespace Survos\FieldBundle\Controller;

use Doctrine\Persistence\ManagerRegistry;
use Survos\FieldBundle\Registry\EntityMetaRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\RouterInterface;

/**
 * Per-entity admin dashboard.
 *
 * Single page that summarises everything the admin cares about for one
 * #[EntityMeta]-registered class:
 *   - Doctrine row count (when an EM is configured for it)
 *   - Meilisearch index info (when meili-bundle is installed and the class
 *     has #[MeiliIndex])
 *   - ux-search availability (when mezcalito/ux-search is installed) — TODO
 *   - Browse link (when api-grid-bundle is installed)
 *
 * The `$code` route param is the snake-cased identity computed at compile
 * time on EntityMetaDescriptor (e.g. "app_song", "pixie_foo").
 */
final class EntityDashboardController extends AbstractController
{
    public function __construct(
        private readonly EntityMetaRegistry $registry,
        private readonly RouterInterface    $router,
        private readonly ?ManagerRegistry   $doctrine = null,
        // MeiliRegistry lives in survos/meili-bundle; injected via service id by
        // the bundle's service definition with NULL_ON_INVALID_REFERENCE so that
        // field-bundle stays usable without meili-bundle.
        private readonly ?object            $meiliRegistry = null,
        private readonly ?object            $chatWorkspaceResolver = null,
    ) {}

    #[Route('/entity/{code}', name: 'survos_entity_dashboard', methods: ['GET'])]
    public function dashboard(string $code): Response
    {
        $descriptor = $this->registry->getByCode($code);
        if (!$descriptor) {
            throw $this->createNotFoundException(sprintf('No #[EntityMeta] entity registered for code "%s".', $code));
        }

        return $this->render('@SurvosField/entity/dashboard.html.twig', [
            'descriptor'             => $descriptor,
            'rowCount'               => $this->resolveRowCount($descriptor->class),
            'meili'                  => $this->resolveMeili($descriptor->class),
            'meiliRegistryAvailable' => $this->meiliRegistry !== null,
            'browseUrl'              => $this->resolveBrowseUrl($code),
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

    /**
     * @return array{baseName: string, indexUid?: string}|null
     */
    private function resolveMeili(string $class): ?array
    {
        if (!$this->meiliRegistry) {
            return null;
        }

        // MeiliRegistry exposes per-class config via a method on the bundle's
        // own contract; gate behind method_exists so we degrade gracefully if
        // the contract evolves.
        foreach (['baseNameForClass', 'getBaseNameForClass'] as $method) {
            if (method_exists($this->meiliRegistry, $method)) {
                $baseName = $this->meiliRegistry->{$method}($class);
                if ($baseName) {
                    return $this->meiliView((string) $baseName);
                }
            }
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

    /**
     * @return array{
     *     baseName: string,
     *     searchUrl: ?string,
     *     chatUrls: list<array{workspace: string, url: string}>
     * }
     */
    private function meiliView(string $baseName): array
    {
        return [
            'baseName' => $baseName,
            'searchUrl' => $this->routeUrl('meili_insta', ['indexName' => $baseName]),
            'chatUrls' => $this->chatUrls($baseName),
        ];
    }

    /**
     * @return list<array{workspace: string, url: string}>
     */
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

    /**
     * @param array<string, string> $parameters
     */
    private function routeUrl(string $route, array $parameters): ?string
    {
        if (!$this->router->getRouteCollection()->get($route)) {
            return null;
        }

        try {
            return $this->router->generate($route, $parameters);
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveBrowseUrl(string $code): ?string
    {
        $route = $this->router->getRouteCollection()->get('survos_admin_browse');
        if (!$route) {
            return null;
        }

        return $this->router->generate('survos_admin_browse', ['code' => $code]);
    }
}
