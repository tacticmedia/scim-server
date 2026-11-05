<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer;

/**
 * @see https://www.rfc-editor.org/rfc/rfc7643#section-10.4
 */
enum SchemaUrn: string
{
    case User = 'urn:ietf:params:scim:schemas:core:2.0:User';
    case EnterpriseUser = 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User';
    case Group = 'urn:ietf:params:scim:schemas:core:2.0:Group';
    case ServiceProviderConfig = 'urn:ietf:params:scim:schemas:core:2.0:ServiceProviderConfig';
    case ResourceType = 'urn:ietf:params:scim:schemas:core:2.0:ResourceType';
    case Schema = 'urn:ietf:params:scim:schemas:core:2.0:Schema';
}
