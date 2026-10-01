<?php

namespace App\Services;

use App\Models\Room;
use RuntimeException;

class RoomCodeGenerator
{
    public const CHARSET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    public const CODE_REGEX = '/^[A-Z0-9]{6}$/';

    /**
     * Generate a cryptographically secure random room code.
     */
    public function generate(int $length = 6): string
    {
        $charsetLength = strlen(self::CHARSET);
        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $randomIndex = random_int(0, $charsetLength - 1);
            $code .= self::CHARSET[$randomIndex];
        }

        return $code;
    }

    /**
     * Generate a unique room code, retrying on collision against existing rooms.
     *
     * @throws RuntimeException
     */
    public function generateUnique(int $length = 6, int $maxRetries = 10): string
    {
        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            $code = $this->generate($length);

            if (!Room::where('room_code', $code)->exists()) {
                return $code;
            }
        }

        throw new RuntimeException("Failed to generate unique room code after {$maxRetries} attempts");
    }

    /**
     * Validate room code format. Accepts any 6-character uppercase alphanumeric code
     * to ensure backward compatibility with legacy codes.
     */
    public function isValid(string $code): bool
    {
        return preg_match(self::CODE_REGEX, strtoupper(trim($code))) === 1;
    }
}
