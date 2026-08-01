<?php

declare(strict_types=1);

namespace Survos\FieldBundle\Controller;

use Survos\FieldBundle\Service\RouteSitemapBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Web-viewable home for field-bundle's agent/dev-facing reports -- the
 * "Fields" nav menu, next to Entity Constants.
 *
 * Three formats of the same data: HTML (readable, for a human), .json
 * (structured, for a script/agent that wants to filter/query it), .txt
 * (markdown, for pasting straight into an agent's context or copy-paste).
 */
final class FieldReportController extends AbstractController
{
    public function __construct(
        private readonly RouteSitemapBuilder $routeSitemap,
    ) {
    }

    #[Route('/routes-sitemap', name: 'survos_routes_sitemap', methods: ['GET'])]
    public function routeSitemap(): Response
    {
        $data = $this->routeSitemap->toArray();

        return $this->render('@SurvosField/report/route_sitemap.html.twig', [
            'coverage'    => $data['coverage'],
            'controllers' => $data['controllers'],
            'rawUrl'      => $this->generateUrl('survos_routes_sitemap_raw'),
            'jsonUrl'     => $this->generateUrl('survos_routes_sitemap_json'),
        ]);
    }

    #[Route('/routes-sitemap.txt', name: 'survos_routes_sitemap_raw', methods: ['GET'])]
    public function routeSitemapRaw(): Response
    {
        return new Response($this->routeSitemap->render(), 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    #[Route('/routes-sitemap.json', name: 'survos_routes_sitemap_json', methods: ['GET'])]
    public function routeSitemapJson(): JsonResponse
    {
        return new JsonResponse($this->routeSitemap->toArray());
    }
}
