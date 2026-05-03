<?php

declare(strict_types=1);

namespace Survos\FieldBundle\Enum;

/**
 * Who is a route for? Drives nav grouping and sitemap inclusion defaults.
 *
 * Descriptive metadata only — actual access control still belongs to
 * #[IsGranted] / security voters. Audience::Public means "anyone can view";
 * it does not enforce that anyone can.
 */
enum Audience: string
{
    case Public        = 'public';
    case Authenticated = 'authenticated';
    case Admin         = 'admin';
    case Api           = 'api';
    case Internal      = 'internal';
}
