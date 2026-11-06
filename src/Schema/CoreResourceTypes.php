<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Schema;

/** @see https://www.rfc-editor.org/rfc/rfc7643#section-8.6 */
final class CoreResourceTypes
{
    public static function user(): ResourceType
    {
        return new ResourceType('User', '/Users', CoreSchemas::user(), [new SchemaExtension(CoreSchemas::enterpriseUser())], 'User Account');
    }

    public static function group(): ResourceType
    {
        return new ResourceType('Group', '/Groups', CoreSchemas::group(), description: 'Group');
    }
}
