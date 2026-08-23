<?php

namespace App\Services\Auth;

use Google\Client as GoogleClient;
use Illuminate\Support\Facades\Http;
use Exception;
use RuntimeException;

class GoogleTokenVerifier
{
    protected GoogleClient $client;
    protected string $clientId;

    public function __construct(?GoogleClient $client = null)
    {
        $clientId = config('services.google.client_id');
        if (empty($clientId)) {
            throw new RuntimeException('GOOGLE_CLIENT_ID environment variable is missing or unconfigured.');
        }

        $this->clientId = $clientId;
        $this->client = $client ?? new GoogleClient(['client_id' => $this->clientId]);
    }

    /**
     * Verify Google ID Token (JWT) or Access Token server-side against Google's public APIs.
     * Validates audience against GOOGLE_CLIENT_ID and checks token expiration.
     *
     * @param string $idToken (JWT ID Token or Access Token)
     * @return array|null Payload array containing 'sub', 'email', 'name', 'picture', etc. or null if invalid.
     */
    public function verify(string $idToken): ?array
    {
        // 1. Try verifying as a JWT ID Token (Standard Android/iOS/Web One-Tap JWT)
        try {
            $this->client->setClientId($this->clientId);
            $payload = $this->client->verifyIdToken($idToken);
            if (is_array($payload) && !empty($payload['sub'])) {
                return $payload;
            }
        } catch (Exception $e) {
            // Fall through to access token verification
        }

        // 2. Try verifying as an OAuth 2.0 Access Token (Flutter Web GIS Token)
        try {
            $tokenInfoResponse = Http::timeout(5)->get('https://oauth2.googleapis.com/tokeninfo', [
                'access_token' => $idToken,
            ]);

            if ($tokenInfoResponse->successful()) {
                $tokenInfo = $tokenInfoResponse->json();
                $aud = $tokenInfo['aud'] ?? $tokenInfo['issued_to'] ?? null;

                // Validate audience claim against configured Client ID
                if ($aud === $this->clientId) {
                    $userInfoResponse = Http::timeout(5)
                        ->withToken($idToken)
                        ->get('https://www.googleapis.com/oauth2/v3/userinfo');

                    if ($userInfoResponse->successful()) {
                        $userInfo = $userInfoResponse->json();
                        return [
                            'sub' => $userInfo['sub'] ?? $tokenInfo['user_id'] ?? null,
                            'email' => $userInfo['email'] ?? $tokenInfo['email'] ?? null,
                            'name' => $userInfo['name'] ?? null,
                            'picture' => $userInfo['picture'] ?? null,
                        ];
                    }
                }
            }
        } catch (Exception $e) {
            // Verification failed
        }

        return null;
    }
}
