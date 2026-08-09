# 🎲 Ludo Multiplayer Backend API

![Laravel](https://img.shields.io/badge/Laravel-12.0-FF2D20?style=for-the-badge&logo=laravel&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-8.2+-777BB4?style=for-the-badge&logo=php&logoColor=white)
![Redis](https://img.shields.io/badge/Redis-DC382D?style=for-the-badge&logo=redis&logoColor=white)
![WebSockets](https://img.shields.io/badge/Reverb-WebSockets-000000?style=for-the-badge)

A robust, real-time backend API for a multiplayer Ludo game, built with **Laravel 12**. This backend handles everything from user authentication and real-time gameplay to chat, friend management, wallet transactions, and in-game store functionalities.

## 🚀 Features

- **User Authentication**: Secure signup, login, and profile management using Laravel Sanctum.
- **Real-time Gameplay**: Powered by Laravel Reverb and Redis for lightning-fast WebSocket connections.
- **Room Management**: Create, join, and manage custom or random game rooms.
- **Wallet System**: Virtual currency management, balance updates, and transaction history.
- **In-Game Store**: Purchase avatars, dice skins, and other game assets.
- **Social Features**: Add friends, manage friend requests, and real-time chat with players.
- **Leaderboards**: Global and weekly ranking system based on player scores and wins.

## 🛠️ Tech Stack

- **Framework**: [Laravel 12](https://laravel.com/)
- **Language**: PHP 8.2+
- **Database**: MySQL / SQLite (configurable)
- **Cache & Queue**: Redis
- **WebSockets**: [Laravel Reverb](https://laravel.com/docs/reverb) for real-time events
- **Authentication**: Laravel Sanctum

## 📦 Installation & Setup

1. **Clone the repository**
   ```bash
   git clone https://github.com/your-username/ludo_backend.git
   cd ludo_backend
   ```

2. **Install dependencies**
   ```bash
   composer install
   npm install
   ```

3. **Environment Setup**
   Copy the example `.env` file and configure your database and Redis settings:
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

4. **Database Migration**
   ```bash
   php artisan migrate
   ```

5. **Install & Start Redis Server**
   This project relies on Redis for queues, caching, and real-time WebSocket communication.
   - **For Windows**: You can install Redis using the `Redis-x64-5.0.14.1.msi` installer file provided right in the root directory of this repository. Just double-click and install it.
   - **For Mac/Linux**: Run `brew install redis` or `sudo apt install redis-server`.
   
   *Make sure the Redis service is running in the background.*

6. **Run the Application Services**
   For the backend to work perfectly with real-time features, you need to open **three separate terminal windows** and run one of these commands in each:

   *Terminal 1 (Starts the Laravel API Server):*
   ```bash
   php artisan serve
   ```

   *Terminal 2 (Starts the Reverb WebSocket Server):*
   ```bash
   php artisan reverb:start
   ```

   *Terminal 3 (Starts the Queue Worker for Background Jobs):*
   ```bash
   php artisan queue:work
   ```

## 🔌 WebSockets (Laravel Reverb)
Real-time features like chat, dice rolls, and player movements are broadcasted via Reverb. Ensure your `.env` contains the correct Reverb configuration:
```env
BROADCAST_CONNECTION=reverb
CACHE_STORE=redis
QUEUE_CONNECTION=redis
```

## 📜 API Modules (Overview)

- `AuthController` - Authentication & Registration
- `GameController` - Core Ludo logic (dice rolls, pawn movements, turns)
- `RoomController` - Multiplayer matchmaking and room generation
- `ChatController` - In-room and private messaging
- `FriendController` - Social graph and friend requests
- `WalletController` - Virtual economy management
- `StoreController` - Shop inventory and purchases
- `LeaderboardController` - High scores and player rankings

## 📄 License
This project is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
