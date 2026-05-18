# Marketsmart Inventory System

PHP/XAMPP inventory system for the MGT BSIT 3rd Year case study.

## Requirements Covered

- PHP and XAMPP stack
- `casestudy` database host with MySQL/MariaDB port `3307`
- Inventory CRUD with Stock-In and Stock-Out transactions
- Pusher realtime notifications and live section sync
- Stored procedures and triggers
- CSRF protection for POST/state-changing requests
- Prepared statements, input validation, and XSS-safe output escaping
- Password hashing with PHP `password_hash()` / `password_verify()`
- SQL schema in `database/schema.sql`
- Environment template in `.env.example`

## Local Setup

1. Map `casestudy` to `127.0.0.1` in the Windows hosts file.
2. Copy `.env.example` to `.env`.
3. Fill in the Pusher credentials in `.env`.
4. Start Apache and MySQL/MariaDB in XAMPP.
5. Import `database/schema.sql`, or open the app once so `backend/db.php` can create/update the schema.
