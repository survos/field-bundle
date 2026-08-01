<?php

declare(strict_types=1);

namespace Survos\FieldBundle\Command;

use Survos\FieldBundle\Service\RouteSitemapBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Human-readable outline of every route: what it does, who it's for --
 * "kinda like a sitemap.xml file" but for reading, not crawling. Grouped by
 * entity (falling back to #[RouteMeta]'s $parents for app-level pages),
 * annotated routes first, then a coverage-gap list.
 *
 * meta:export (MetaExportCommand) already dumps the same underlying data as
 * machine-readable JSON/YAML for tooling; this command is the human-facing
 * sibling. Also web-viewable — see FieldReportController, Fields nav menu.
 *
 * Rendering logic lives in RouteSitemapBuilder, shared with that controller.
 */
#[AsCommand('field:routes:sitemap', 'Render a human-readable outline of every route (description, audience, hierarchy) plus a coverage-gap list.')]
final class RouteSitemapCommand
{
    public function __construct(
        private readonly RouteSitemapBuilder $builder,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Write markdown to a file instead of stdout')] ?string $output = null,
        #[Option('Skip the coverage-gap section')] bool $noGaps = false,
    ): int {
        $markdown = $this->builder->render(includeGaps: !$noGaps);

        if ($output !== null && $output !== '') {
            file_put_contents($output, $markdown);
            $io->success("Wrote sitemap to {$output}");
            return Command::SUCCESS;
        }

        $io->writeln($markdown);
        return Command::SUCCESS;
    }
}
