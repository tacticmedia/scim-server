<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Repository;

use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Path\AttributePath;

/**
 * A repository that changes some multi-valued attributes by delta, so that PATCH never loads their values. For a
 * PATCH, the server reads the resource with get() and these attributes in $excludedAttributes, applies the operations
 * on other attributes itself, and calls patch() with the result and the steps on these attributes. A request with an
 * operation on these attributes that is not a step takes the path of {@see ResourceRepository::replace()}.
 *
 * get() without these attributes must return "meta.version", and every change of their values must change it.
 */
interface DeltaRepository extends ResourceRepository
{
    /**
     * Each attribute is multi-valued, complex, readWrite and not required, and has a "value" and no "primary"
     * sub-attribute, else the server throws \LogicException.
     *
     * @return list<AttributePath> URN-qualified; [] turns deltas off
     */
    public function deltaAttributes(): array;

    /**
     * One transaction: the document, then the steps in order. Nothing changes when it throws. A call without an
     * effective change and without a document keeps "meta.lastModified" and "meta.version".
     *
     * @throws ScimException 404 when the resource does not exist, 412 when it no longer has $patch->version, 400
     *                       invalidValue for a "value" of an Add or Replace step that names no resource
     */
    public function patch(string $id, ResourcePatch $patch): PatchResult;
}
