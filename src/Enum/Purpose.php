<?php

declare(strict_types=1);

namespace Survos\FieldBundle\Enum;

/**
 * What a route does relative to its entity.
 *
 * Free-form vocabulary; these are the canonical cases the registry understands
 * for graph queries (showRouteFor, missingPurposes, …). Use Purpose::Custom for
 * anything that does not fit.
 */
enum Purpose: string
{
    case List      = 'list';        // collection listing — was 'index' in Symfony Maker conventions
    case Show      = 'show';
    case Dashboard = 'dashboard';
    case New       = 'new';
    case Edit      = 'edit';
    case Delete    = 'delete';
    case Export    = 'export';
    case Api       = 'api';
    case Custom    = 'custom';
}
