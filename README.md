# Divvy API

Laravel REST API backend for Divvy - A bill splitting application with GCash integration.

## Tech Stack

- Laravel 11.x
- MySQL
- Laravel Sanctum (Authentication)
- Firebase Admin SDK (Push notifications)
- PayMongo API (GCash/PayMaya payments)

## Setup

1. Install dependencies:

```bash
composer install
```

2. Copy environment file:

```bash
cp .env.example .env
```

3. Generate application key:

```bash
php artisan key:generate
```

4. Configure database in `.env`:

```
DB_DATABASE=divvy
DB_USERNAME=your_username
DB_PASSWORD=your_password
```

5. Run migrations:

```bash
php artisan migrate
```

6. Start development server:

```bash
php artisan serve
```

## API Documentation

API documentation will be available at `/api/documentation` once implemented.

## Testing

Run tests with:

```bash
php artisan test
```

## Related Repositories

- Frontend: [divvy-app](https://github.com/yourusername/divvy-app)
