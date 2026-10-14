<?php

namespace App\Support;

use Ramsey\Uuid\Uuid as RamseyUuid;

/**
 * UUID v4 generation/validation — port of the legacy Uuid.php.
 */
class Uuid
{
    public static function v4(): string
    {
        return RamseyUuid::uuid4()->toString();
    }

    public static function isValid(string $uuid): bool
    {
        return RamseyUuid::isValid($uuid);
    }
}
