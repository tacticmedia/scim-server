<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Repository;

use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Query\ListQuery;
use TacticMedia\ScimServer\Query\ListResult;

/**
 * The storage of one resource type. Documents are SCIM resources as arrays in schema spelling, with extension
 * attributes under their schema URN key. The server validates every document before it calls create() or
 * replace(), and it applies PATCH operations itself.
 *
 * A repository sets "id" and the "meta" sub-attributes "created", "lastModified" and, optionally, "version". The
 * server sets "meta.resourceType" and "meta.location".
 *
 * The server checks If-Match against the document that get() returned. For an If-Match with an entity tag, it then
 * passes the stored "meta.version" of that document to replace() or delete(). A repository that writes only while the
 * resource still has that version makes If-Match atomic; one that ignores it leaves a race between the check and the
 * write.
 */
interface ResourceRepository
{
    /**
     * The hints are resolved as in {@see ListQuery}. The server passes them for GET of one resource, for example
     * excludedAttributes=members (Entra), and for a PATCH through a {@see DeltaRepository}: the delta attributes as
     * $excludedAttributes, then the projection of the request for a 200 response. A repository that skips attributes
     * sets "meta.version", because the server otherwise derives the ETag from the returned document.
     *
     * @param list<AttributePath> $attributes         a hint: a repository can skip what the response omits
     * @param list<AttributePath> $excludedAttributes a hint: a repository can skip what the response omits
     *
     * @return array<string, mixed>|null
     */
    public function get(string $id, array $attributes = [], array $excludedAttributes = []): ?array;

    /**
     * @throws ScimException when the repository cannot evaluate the filter, for example 400 invalidFilter
     */
    public function list(ListQuery $query): ListResult;

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     *
     * @throws ScimException for example 409 uniqueness
     */
    public function create(array $document): array;

    /**
     * @param array<string, mixed> $document the complete new state
     * @param string|null          $version  the "meta.version" that the resource must still have; null: no condition
     *
     * @return array<string, mixed>
     *
     * @throws ScimException 404 when the resource does not exist, 412 when it no longer has $version, or for example
     *                       409 uniqueness
     */
    public function replace(string $id, array $document, ?string $version = null): array;

    /**
     * @param string|null $version the "meta.version" that the resource must still have; null: no condition
     *
     * @throws ScimException 404 when the resource does not exist, 412 when it no longer has $version
     */
    public function delete(string $id, ?string $version = null): void;
}
