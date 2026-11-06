<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer;

/** @see https://www.rfc-editor.org/rfc/rfc7644#section-8.2 */
enum MessageUrn: string
{
    case ListResponse = 'urn:ietf:params:scim:api:messages:2.0:ListResponse';
    case SearchRequest = 'urn:ietf:params:scim:api:messages:2.0:SearchRequest';
    case PatchOp = 'urn:ietf:params:scim:api:messages:2.0:PatchOp';
    case BulkRequest = 'urn:ietf:params:scim:api:messages:2.0:BulkRequest';
    case BulkResponse = 'urn:ietf:params:scim:api:messages:2.0:BulkResponse';
    case Error = 'urn:ietf:params:scim:api:messages:2.0:Error';
}
