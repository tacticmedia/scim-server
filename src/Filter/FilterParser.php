<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Filter;

use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Patch\PatchPath;
use TacticMedia\ScimServer\Path\AttributePath;

/**
 * Parses filters and PATCH paths into unresolved trees: the paths keep the spelling of the input. {@see FilterResolver}
 * resolves them against a resource type.
 *
 * @see https://www.rfc-editor.org/rfc/rfc7644#section-3.4.2.2
 * @see https://www.rfc-editor.org/rfc/rfc7644#section-3.5.2
 */
final readonly class FilterParser
{
    /**
     * The value filter ends at the last "]", because neither an attribute path nor a sub-attribute name contains one.
     */
    private const string PATCH_PATH = '/^(?<attribute>[^\[\]]+)(?:\[(?<filter>.*)\](?:\.(?<subAttribute>'.AttributePath::NAME.'))?)?\z/si';

    /**
     * @throws ScimException invalidFilter
     */
    public function parse(string $filter): Filter
    {
        return ExpressionParser::parse($filter);
    }

    /**
     * PATH = attrPath / valuePath [subAttr]. For emails[type eq "work"].value the path is emails.value and the value
     * filter holds emails.type eq "work".
     *
     * @throws ScimException invalidPath when the path does not parse, invalidFilter when its value filter does not
     */
    public function parsePatchPath(string $path): PatchPath
    {
        if (1 !== preg_match(self::PATCH_PATH, $path, $parts, \PREG_UNMATCHED_AS_NULL)) {
            throw $this->invalidPath($path);
        }

        $attributePath = AttributePath::parse($parts['attribute']) ?? throw $this->invalidPath($path);

        if (null === $parts['filter']) {
            return new PatchPath($attributePath);
        }

        if (null !== $attributePath->subAttribute) {
            throw $this->invalidPath($path);
        }

        return new PatchPath(
            $attributePath->withSubAttribute($parts['subAttribute']),
            ExpressionParser::parse($parts['filter'], $attributePath),
        );
    }

    private function invalidPath(string $path): ScimException
    {
        return ScimException::invalidPath(\sprintf("'%s' is not a valid path. Expected attrPath, attrPath[valFilter] or attrPath[valFilter].subAttr.", $path));
    }
}
