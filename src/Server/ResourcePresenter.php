<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Server;

use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Resource\DocumentProjector;
use TacticMedia\ScimServer\Schema\ResourceType;

/**
 * Turns a stored document into what the client gets: meta.resourceType, meta.location and meta.version set, absent
 * "$ref" values filled, the projection applied, and null and empty values left out, so that an empty complex value
 * never becomes a JSON array.
 *
 * @internal
 *
 * @see https://www.rfc-editor.org/rfc/rfc7643#section-3.1
 */
final readonly class ResourcePresenter
{
    /** @var list<ResourceType> */
    private array $referenceTargets;

    /**
     * @param list<RegisteredResourceType> $resourceTypes the types that a "$ref" can name, besides the presented type
     */
    public function __construct(
        array $resourceTypes = [],
        private DocumentProjector $projector = new DocumentProjector(),
    ) {
        $this->referenceTargets = array_map(static fn (RegisteredResourceType $registered): ResourceType => $registered->type, $resourceTypes);
    }

    /**
     * @param array<string, mixed> $document
     * @param list<AttributePath>  $requested the attributes that the request body names
     *
     * @return array<string, mixed>
     */
    public function present(array $document, ResourceType $type, string $baseUrl, RequestedProjection $projection, array $requested = []): array
    {
        $document = $this->withServerValues($document, $type, $baseUrl);

        return array_filter(
            array_map(self::withoutEmptyValues(...), $this->projector->project($document, $type, $projection->attributes, $projection->excludedAttributes, $requested)),
            self::isAssigned(...),
        );
    }

    /**
     * The document with meta.resourceType, meta.location and meta.version set and absent "$ref" values filled. present()
     * of the result gives the same as present() of the document.
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    public function withServerValues(array $document, ResourceType $type, string $baseUrl): array
    {
        $meta = $document['meta'] ?? null;
        $document['meta'] = array_replace(['resourceType' => null], \is_array($meta) ? $meta : [], [
            'resourceType' => $type->name,
            'location' => $this->location($document, $type, $baseUrl),
            'version' => ETag::of($document),
        ]);

        return new ReferenceUrls($baseUrl, [$type, ...$this->referenceTargets])->fill($document, $type);
    }

    /**
     * @param array<string, mixed> $document
     */
    public function location(array $document, ResourceType $type, string $baseUrl): string
    {
        $id = $document['id'] ?? null;

        return new ReferenceUrls($baseUrl)->of($type, \is_string($id) ? $id : throw new \UnexpectedValueException('The repository returned a document without a string "id".'));
    }

    private static function withoutEmptyValues(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        $assigned = array_filter(array_map(self::withoutEmptyValues(...), $value), self::isAssigned(...));

        return array_is_list($value) ? array_values($assigned) : $assigned;
    }

    private static function isAssigned(mixed $value): bool
    {
        return null !== $value && [] !== $value;
    }
}
