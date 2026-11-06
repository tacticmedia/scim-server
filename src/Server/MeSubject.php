<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Server;

/**
 * The resource of the authenticated subject: the resource with $value in $attribute. "id" needs no query; another
 * attribute is looked up with an "eq" filter, so it must be filterable.
 */
final readonly class MeSubject
{
    /**
     * @param string $resourceType the name of a registered resource type, for example "User"
     * @param string $attribute    "id", or an attribute path, for example "userName" or "emails.value"
     */
    public function __construct(
        public string $resourceType,
        public string $value,
        public string $attribute = 'id',
    ) {
    }
}
