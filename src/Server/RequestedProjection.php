<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Server;

use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Schema\ResolvedAttribute;
use TacticMedia\ScimServer\Schema\ResourceType;

/**
 * @internal
 *
 * @see https://www.rfc-editor.org/rfc/rfc7644#section-3.9
 */
final readonly class RequestedProjection
{
    /**
     * @param list<AttributePath> $attributes
     * @param list<AttributePath> $excludedAttributes
     */
    public function __construct(
        public array $attributes = [],
        public array $excludedAttributes = [],
    ) {
    }

    /**
     * @throws ScimException invalidValue for a malformed parameter, or when both parameters name attributes
     */
    public static function from(RequestParameters $parameters): self
    {
        $projection = new self($parameters->paths('attributes'), $parameters->paths('excludedAttributes'));

        if ([] !== $projection->attributes && [] !== $projection->excludedAttributes) {
            throw ScimException::invalidValue('The parameters "attributes" and "excludedAttributes" are mutually exclusive.');
        }

        return $projection;
    }

    /**
     * The repository hints: qualified paths in schema spelling, without the paths that $type does not define.
     */
    public function resolvedFor(ResourceType $type): self
    {
        return new self($this->resolve($this->attributes, $type), $this->resolve($this->excludedAttributes, $type));
    }

    /**
     * @param list<AttributePath> $paths
     *
     * @return list<AttributePath>
     */
    private function resolve(array $paths, ResourceType $type): array
    {
        return array_map(
            static fn (ResolvedAttribute $attribute): AttributePath => $attribute->path(),
            array_merge(...array_map($type->resolveSelection(...), $paths)),
        );
    }
}
