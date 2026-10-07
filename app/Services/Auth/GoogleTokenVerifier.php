<?php

namespace App\Services\Auth;

use Google\Client as GoogleClient;
use Illuminate\Support\Facades\Http;
use Exception;
use RuntimeException;

class GoogleTokenVerifier
{
    protected ?GoogleClient $client = null;
    protected string $clientId;

    public function __construct(?GoogleClient $client = null)
    {
        $clientId = config('services.google.client_id', '741692358771-gr8b608j5l7ck4bufcare9stoerqvfav.apps.googleusercontent.com');
        $this->clientId = $clientId ?: '741692358771-gr8b608j5l7ck4bufcare9stoerqvfav.apps.googleusercontent.com';

        try {
            $this->client = $client ?? new GoogleClient(['client_id' => $this->clientId]);
        } catch (Exception $e) {
            $this->client = null;
        }
    }

    /**
     * Verify Google ID Token (JWT) or Access Token server-side against Google's public APIs.
     * Validates token directly with Google.
     *
     * @param string $idToken (JWT ID Token or Access Token)
     * @return array|null Payload array containing 'sub', 'email', 'name', 'picture', etc. or null if invalid.
     */
    public function verify(string $idToken): ?array
    {
        // 1. Try verifying via Google Client Library (Standard JWT ID Token)
        if ($this->client) {
            try {
                $this->client->setClientId($this->clientId);
                $payload = $this->client->verifyIdToken($idToken);
                if (is_array($payload) && !empty($payload['sub'])) {
                    return [
                        'sub' => $payload['sub'],
                        'email' => $payload['email'] ?? null,
                        'name' => $payload['name'] ?? null,
                        'picture' => $payload['picture'] ?? null,
                    ];
                }
            } catch (Exception $e) {
                // Fall through to HTTP verification endpoints
            }
        }

        // 2. Try verifying via Google OAuth2 TokenInfo API directly (HTTP tokeninfo for ID tokens)
        try {
            $tokenInfoResponse = Http::timeout(8)->get('https://oauth2.googleapis.com/tokeninfo', [
                'id_token' => $idToken,
            ]);

            if ($tokenInfoResponse->successful()) {
                $data = $tokenInfoResponse->json();
                if (!empty($data['sub'])) {
                    return [
                        'sub' => $data['sub'],
                        'email' => $data['email'] ?? null,
                        'name' => $data['name'] ?? null,
                        'picture' => $data['picture'] ?? null,
                    ];
                }
            }
        } catch (Exception $e) {
            // Fall through to UserInfo API
        }

        // 3. Try verifying as an OAuth 2.0 Access Token via Google UserInfo API
        try {
            $userInfoResponse = Http::timeout(8)
                ->withToken($idToken)
                ->get('https://www.googleapis.com/oauth2/v3/userinfo');

            if ($userInfoResponse->successful()) {
                $userInfo = $userInfoResponse->json();
                if (!empty($userInfo['sub'])) {
                    return [
                        'sub' => $userInfo['sub'],
                        'email' => $userInfo['email'] ?? null,
                        'name' => $userInfo['name'] ?? null,
                        'picture' => $userInfo['picture'] ?? null,
                    ];
                }
            }
        } catch (Exception $e) {
            // Verification failed
        }

        return null;
    }
}
