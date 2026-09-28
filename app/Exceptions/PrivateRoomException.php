<?php

namespace App\Exceptions;

use RuntimeException;

class PrivateRoomException extends RuntimeException
{
    public function __construct(
        string $message,
        protected string $errorCode,
        int $statusCode = 400,
        public readonly ?int $roomId = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getStatusCode(): int
    {
        return $this->getCode();
    }
    public function render(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $payload = [
            'error_code' => $this->errorCode,
            'message' => $this->getMessage(),
        ];

        if ($this->roomId !== null) {
            $payload['room_id'] = $this->roomId;
        }

        return response()->json($payload, $this->getStatusCode());
    }

    public static function notFound(string $message = 'Room not found'): self
    {
        return new self($message, 'ROOM_NOT_FOUND', 404);
    }

    public static function full(string $message = 'Room is full'): self
    {
        return new self($message, 'ROOM_FULL', 409);
    }

    public static function alreadyStarted(string $message = 'Room has already started'): self
    {
        return new self($message, 'ROOM_ALREADY_STARTED', 409);
    }

    public static function insufficientBalance(string $message = 'Insufficient balance'): self
    {
        return new self($message, 'INSUFFICIENT_BALANCE', 402);
    }

    public static function notHost(string $message = 'Only the host can perform this action'): self
    {
        return new self($message, 'NOT_HOST', 403);
    }

    public static function notMember(string $message = 'You are not a member of this room'): self
    {
        return new self($message, 'NOT_A_MEMBER', 403);
    }

    public static function notEnoughPlayers(string $message = 'Not enough players to start'): self
    {
        return new self($message, 'NOT_ENOUGH_PLAYERS', 409);
    }

    public static function playersNotReady(string $message = 'All players must be ready to start'): self
    {
        return new self($message, 'PLAYERS_NOT_READY', 409);
    }

    public static function alreadyInRoom(string $message = 'User is already in an active room', ?int $roomId = null): self
    {
        return new self($message, 'ALREADY_IN_ROOM', 409, $roomId);
    }

    public static function invalidSettings(string $message = 'Invalid room settings'): self
    {
        return new self($message, 'INVALID_SETTINGS', 422);
    }
}
