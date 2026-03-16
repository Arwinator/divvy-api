# Divvy API

> Backend API for Divvy, a bill splitting app.

**⚠️ Work in Progress** - Actively being developed.

## Overview

Bill splitting app targeting the Philippine market. Split expenses with friends and pay through GCash or PayMaya.

## Features

- User authentication and registration
- Create and manage groups
- Split bills equally or set custom amounts
- Process payments via GCash and PayMaya
- Track transaction history
- Push notifications for bill updates
- Offline sync support

## Tech Stack

- Laravel 11.x
- MySQL
- Laravel Sanctum (authentication)
- Firebase Admin SDK (push notifications)
- PayMongo API (payment processing)
- Laravel Queue (background jobs)

## Getting Started

### Prerequisites

- PHP 8.2+
- Composer
- MySQL 5.7+
- Firebase project
- PayMongo account

### Installation

Clone the repository:

```bash
git clone https://github.com/Arwinator/divvy-api.git
cd divvy-api
```

Install dependencies:

```bash
composer install
```

Set up environment variables:

```bash
# Linux / macOS
cp .env.example .env

# Windows (Command Prompt)
copy .env.example .env

# Windows (PowerShell)
Copy-Item .env.example .env
```

**Important:** Update the `.env` file with your database credentials, PayMongo keys, and Firebase configuration before proceeding.

Generate application key:

```bash
php artisan key:generate
```

Run migrations:

```bash
php artisan migrate
```

Start the server:

```bash
php artisan serve
```

### Environment Setup

Update your `.env` file with these values:

**Database Configuration**

```env
DB_DATABASE=divvy
DB_USERNAME=your_username
DB_PASSWORD=your_password
```

**PayMongo Configuration**

```env
PAYMONGO_SECRET_KEY=sk_test_...
PAYMONGO_PUBLIC_KEY=pk_test_...
PAYMONGO_WEBHOOK_SECRET=whsec_...
```

**Firebase Configuration**

- Download your Firebase service account JSON from Firebase Console
- Place it in `storage/firebase-credentials.json`

### Queue Worker

For push notifications to work, start the queue worker:

```bash
php artisan queue:work
```

## Architecture

- **Repository Pattern** for data access
- **Service Layer** for business logic (PaymentService, NotificationService)
- **Form Requests** for validation
- **Middleware** for authorization and group membership checks
- **Queued Jobs** for background notification delivery

## Testing

```bash
php artisan test
```

## Known Limitations

Some features are not yet implemented:

- Account deletion
- Bill editing after creation
- Group ownership transfer
- Receipt photo upload
- Payment reminders

## Related

- Mobile App: [divvy-app](https://github.com/Arwinator/divvy-app) (Flutter, in progress)

---

**Built by [@Arwinator](https://github.com/Arwinator)**
