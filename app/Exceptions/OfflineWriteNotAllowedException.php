<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Thrown when a create/update/delete/upload is attempted on the embedded
 * on-device Laravel instance while genuinely offline. After the online
 * routing cutover (nativephp Android RequestRouter.kt), the embedded
 * instance only ever receives a mutation request when the device has no
 * connectivity at all — there is no local-first write path anymore, so the
 * correct response is a clean rejection, not a queued pending_* row.
 */
class OfflineWriteNotAllowedException extends HttpException
{
    public function __construct(string $message = 'This action requires an internet connection.')
    {
        parent::__construct(503, $message);
    }
}
