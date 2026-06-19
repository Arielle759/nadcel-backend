# NADCEL Backend

Plateforme web de réservation multi-salons de beauté.

## Stack

- **Framework** : Laravel 10
- **Database** : PostgreSQL
- **Auth** : JWT
- **API** : REST

## Setup Local

### Prerequisites
- PHP 8.3+
- PostgreSQL
- Composer

### Installation

```bash
git clone git@github.com:Arielle759/nadcel-backend.git
cd nadcel-backend

composer install
cp .env.example .env
php artisan key:generate
php artisan jwt:secret

psql -U postgres -c "CREATE DATABASE nadcel;"
php artisan migrate

php artisan serve
```

### URLs
- API : http://localhost:8000/api

## Database Tables

- users
- salons
- services
- employees
- employee_service
- appointments
- reviews
- salon_closures

## Authors

Groupe NADCEL - ISI 2026