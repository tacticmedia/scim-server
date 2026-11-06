<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Patch;

/** @see https://www.rfc-editor.org/rfc/rfc7644#section-3.5.2 */
enum PatchOperationType: string
{
    case Add = 'add';
    case Replace = 'replace';
    case Remove = 'remove';

    /**
     * Microsoft Entra ID sends "Add", "Replace" and "Remove".
     */
    public static function tryFromName(string $name): ?self
    {
        return self::tryFrom(strtolower($name));
    }
}
