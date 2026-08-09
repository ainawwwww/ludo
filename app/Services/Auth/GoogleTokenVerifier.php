<?php

namespace App\Services\Auth;

use Google\Client as GoogleClient;
use Exception;

class GoogleTokenVerifier
{
    protected ?GoogleClient $client = null;

    public function __construct(?GoogleClient $client = null)
    {
        $clientId = config('services.google.client_id');
        $this->client = $client ?? new GoogleClient(['client_id' => $clientId]);
    }

    /**
     * Verify Google ID Token server-side against Google's public keys.
     * Validates audience against GOOGLE_CLIENT_ID and checks token expiration.
     *
     * @param string $idToken
     * @return array|null Payload array containing 'sub', 'email', 'name', 'picture', etc. or null if invalid.
     */
    public function verify(string $idToken): ?array
    {
        try {
            $payload = $this->client->verifyIdToken($idToken);
            return is_array($payload) ? $payload : null;
        } catch (Exception $e) {
            return null;
        }
    }
}
