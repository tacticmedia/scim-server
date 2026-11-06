<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Resource;

use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Schema\ResolvedAttribute;
use TacticMedia\ScimServer\Schema\ResourceType;

/**
 * A list of attribute paths resolved with {@see ResourceType::resolveSelection()}. Paths that do not resolve are
 * ignored.
 *
 * @internal
 */
final readonly class AttributeSelection
{
    /**
     * @param array<string, true> $paths             qualified paths in schema spelling
     * @param array<string, true> $parentsOfSubPaths the parents of the named sub-attributes
     */
    private function __construct(
        private bool $empty,
        private array $paths,
        private array $parentsOfSubPaths,
    ) {
    }

    /**
     * @param list<AttributePath> $paths
     */
    public static function of(array $paths, ResourceType $type): self
    {
        $resolved = array_map(
            static fn (ResolvedAttribute $attribute): AttributePath => $attribute->path(),
            array_merge(...array_map($type->resolveSelection(...), $paths)),
        );
        $subPaths = array_filter($resolved, static fn (AttributePath $path): bool => null !== $path->subAttribute);

        return new self(
            [] === $paths,
            array_fill_keys(array_map(strval(...), $resolved), true),
            array_fill_keys(array_map(static fn (AttributePath $path): string => (string) $path->parent(), $subPaths), true),
        );
    }

    public function isEmpty(): bool
    {
        return $this->empty;
    }

    /**
     * True when the attribute or, for a sub-attribute, its parent is named.
     */
    public function names(ResolvedAttribute $attribute): bool
    {
        $path = $attribute->path();

        return isset($this->paths[(string) $path]) || isset($this->paths[(string) $path->parent()]);
    }

    /**
     * True when {@see names()} is, or when a sub-attribute of the attribute is named.
     */
    public function selects(ResolvedAttribute $attribute): bool
    {
        return $this->names($attribute) || isset($this->parentsOfSubPaths[(string) $attribute->path()]);
    }
}
