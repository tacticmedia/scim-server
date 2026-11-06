<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Server;

use Symfony\Component\HttpFoundation\Request;
use TacticMedia\ScimServer\Exception\ScimException;

/**
 * Maps the authenticated subject of a request to its resource, for /Me.
 *
 * @see https://www.rfc-editor.org/rfc/rfc7644#section-3.11
 */
interface MeResolver
{
    /**
     * @return MeSubject|null null when the subject has no resource
     *
     * @throws ScimException to reject the request, for example 403
     */
    public function resolve(Request $request): ?MeSubject;
}
