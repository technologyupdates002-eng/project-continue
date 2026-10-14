<?php

namespace App\Http\Controllers;

use App\Exceptions\HttpException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use AuthorizesRequests;
    use ValidatesRequests;

    protected function identity(): array
    {
        $identity = request()->attributes->get('identity');

        if (!is_array($identity)) {
            throw new HttpException(401, 'invalid_token', 'No identity in request context.');
        }

        return $identity;
    }

    protected function requireRole(string ...$roles): array
    {
        $identity = $this->identity();
        $identityRoles = $identity['roles'] ?? [];

        if (count(array_intersect($identityRoles, $roles)) === 0) {
            throw new HttpException(403, 'insufficient_permissions', 'Insufficient permissions.');
        }

        return $identity;
    }
}
