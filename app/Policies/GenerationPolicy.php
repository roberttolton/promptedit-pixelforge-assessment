<?php

namespace App\Policies;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class GenerationPolicy
{
    /**
     * Another user's generation answers 404, not 403, so its existence is not confirmed.
     */
    public function view(User $user, Generation $generation): Response
    {
        return $user->is($generation->user)
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
