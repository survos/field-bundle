<?php

declare(strict_types=1);

namespace Survos\FieldBundle\Controller;

use Survos\FieldBundle\Service\RouteSitemapBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Web-viewable home for field-bundle's agent/dev-facing reports -- the
 * "Fields" nav menu, next to Entity Constants. Plaintext-in-a-<pre> on
 * purpose (no markdown-to-HTML dependency): this is meant to be read raw
 * by a human OR pasted straight into an agent's context, not styled prose.
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
        return $this->render('@SurvosField/report/route_sitemap.html.twig', [
            'markdown' => $this->routeSitemap->render(),
            'coverage' => $this->routeSitemap->coverage(),
            'rawUrl'   => $this->generateUrl('survos_routes_sitemap_raw'),
        ]);
    }

    #[Route('/routes-sitemap.txt', name: 'survos_routes_sitemap_raw', methods: ['GET'])]
    public function routeSitemapRaw(): Response
    {
        return new Response($this->routeSitemap->render(), 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
