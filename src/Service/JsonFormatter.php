<?php

declare(strict_types=1);

namespace Survos\FieldBundle\Service;

use Twig\Attribute\AsTwigFilter;

final class JsonFormatter
{
    /**
     * Pretty-print JSON or JSON-serializable data for Twig templates.
     *
     * @param array<string, scalar>|list<mixed> $wrapperAttributes
     */
    #[AsTwigFilter('json_pretty', isSafe: ['html'])]
    public function pretty(string|object|array|null $value, ?string $wrapper = 'pre', array $wrapperAttributes = []): string
    {
        $data = $value;
        if (is_string($value) && json_validate($value)) {
            $data = json_decode($value, flags: JSON_THROW_ON_ERROR);
        }

        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        $json = htmlspecialchars($json, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        if ($wrapper === null || $wrapper === '') {
            return $json;
        }

        return sprintf(
            '<%s%s>%s</%s>',
            $wrapper,
            $this->formatAttributes($wrapperAttributes),
            $json,
            $wrapper,
        );
    }

    /** @param array<string, scalar>|list<mixed> $attributes */
    private function formatAttributes(array $attributes): string
    {
        $html = '';
        foreach ($attributes as $name => $value) {
            if (!is_string($name) || $name === '' || $value === false || $value === null) {
                continue;
            }

            $escapedName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            if ($value === true) {
                $html .= ' ' . $escapedName;
                continue;
            }

            $html .= sprintf(
                ' %s="%s"',
                $escapedName,
                htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            );
        }

        return $html;
    }
}
