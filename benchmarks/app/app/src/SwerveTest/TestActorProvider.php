<?php

namespace App\SwerveTest;

use Spiral\Auth\ActorProviderInterface;
use Spiral\Auth\TokenInterface;

/** The actor is the name the token was created for. */
final class TestActorProvider implements ActorProviderInterface
{
    public function getActor(TokenInterface $token): ?object
    {
        return (object) ['name' => $token->getPayload()['name']];
    }
}
