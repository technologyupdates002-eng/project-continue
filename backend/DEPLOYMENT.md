# KHWWC Production Deployment Guide

## Overview

This guide documents the exact steps to deploy the KHWWC Welfare System to the production cPanel/LiteSpeed server.

**Production Domain:** `https://app1.kirinyagahealthcareworkerswelfare.co.ke`  
**Production Public Directory:** `/home/kirinyag/public_html/app1.kirinyagahealthcareworkerswelfare.co.ke/khwwc/public`

## Authoritative Production Stack

- **Backend:** Laravel 12.67.0
- **Production PHP:** 8.4
- **Database:** MySQL
- **Frontend:** React + Vite + TypeScript
- **Authentication:** JWT
- **Web Server:** cPanel / LiteSpeed

---

## Prerequisites

- cPanel access with file manager or FTP
- MySQL database created: `kirinyag_health`
- MySQL user created: `kirinyag_Deno` with appropriate permissions
- **PHP 8.4** (authoritative production runtime)
- Composer (if not including `vendor/` in the package)

---

## Step 1: Upload the Package

1. Upload `KHWW-production-YYYYMMDD-HHMMSS.zip` to the cPanel server
2. Extract the ZIP into `/home/kirinyag/public_html/app1.kirinyagahealthcareworkerswelfare.co.ke/`

After extraction, the directory structure should be:

```
/home/kirinyag/public_html/app1.kirinyagahealthcareworkerswelfare.co.ke/
├── khwwc/
│   ├── app/
│   ├── bootstrap/
│   ├── config/
│   ├── database/
│   ├── public/          ← This becomes the web root
│   ├── resources/
│   ├── routes/
│   ├── storage/
│   ├── vendor/          ← Included if Composer is unavailable on server
│   ├── .env.production.example
│   ├── artisan
│   └── composer.json
├── deploy.sh
└── (other package files)
```

---

## Step 2: Configure the Public Directory

The Laravel `public/` directory must be the web-exposed directory.

**Option A: If `khwwc/public/` is already the document root**

No action needed. The `.htaccess` in `public/` handles Laravel routing.

**Option B: If the document root is the parent directory**

1. Move all contents of `khwwc/public/` to the parent directory
2. Update the paths in `index.php` and `.htaccess` if necessary

---

## Step 3: Create `.env`

1. Copy `.env.production.example` to `.env`
2. Update the following **required** variables:

```env
APP_KEY=base64:GENERATE_WITH_PHP_ARTISAN_KEY_GENERATE
APP_DEBUG=false
APP_URL=https://app1.kirinyagahealthcareworkerswelfare.co.ke

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=kirinyag_health
DB_USERNAME=kirinyag_Deno
DB_PASSWORD=your_database_password

JWT_SECRET=your_secure_jwt_secret_here

COOP_CONSUMER_KEY=your_bank_consumer_key
COOP_CONSUMER_SECRET=your_bank_consumer_secret
COOP_ACCOUNT_NUMBER=your_bank_account_number
COOP_ENABLE_REAL_CALLS=true
COOP_ENVIRONMENT=production
```

3. Generate the application key:

```bash
php artisan key:generate
```

---

## Step 4: Set Permissions

```bash
# Ensure storage is writable
chmod -R 775 storage/
chmod -R 775 bootstrap/cache/

# Ensure .env is not web-accessible (should already be outside public/)
# Verify .htaccess protection
```

---

## Step 5: Run Deployment Script

```bash
cd /home/kirinyag/public_html/app1.kirinyagahealthcareworkerswelfare.co.ke/khwwc
bash deploy.sh
```

The script will:
1. Verify PHP version
2. Check for `.env` file
3. Clear all caches
4. Run migrations with `--force`
5. Optimize for production
6. Perform a health check

---

## Step 6: Configure Callback URLs

In the Co-operative Bank portal, configure the following callback URLs:

```
https://app1.kirinyagahealthcareworkerswelfare.co.ke/api/banking/coop/callback/transfer
https://app1.kirinyagahealthcareworkerswelfare.co.ke/api/banking/coop/callback/stk
https://app1.kirinyagahealthcareworkerswelfare.co.ke/api/banking/coop/callback/ins
https://app1.kirinyagahealthcareworkerswelfare.co.ke/api/banking/coop/callback/validation
```

---

## Step 7: Verify Deployment

1. **Homepage:** Visit `https://app1.kirinyagahealthcareworkerswelfare.co.ke` — should load the React frontend
2. **Health Check:** Visit `https://app1.kirinyagahealthcareworkerswelfare.co.ke/api/health` — should return JSON with status `ok`
3. **Login:** Test with seeded super admin credentials:
   - Email: `254700000001@welfare.local`
   - Password: `Super2026`
4. **Bank Diagnostics:** Navigate to Admin → Banking Diagnostics and run a connectivity test
5. **Logs:** Check `storage/logs/laravel.log` for any errors

---

## Step 8: Post-Deployment

1. **Change default passwords** for all seeded users
2. **Configure bank credentials** in `.env`
3. **Set up cron job** for Laravel scheduler (if not already configured):

```bash
* * * * * cd /home/kirinyag/public_html/app1.kirinyagahealthcareworkerswelfare.co.ke/khwwc && php artisan schedule:run >> /dev/null 2>&1
```

4. **Configure backup settings** in the Super Admin panel

---

## Rollback

If deployment fails:

1. Restore the previous version from backup
2. Restore the database from backup
3. Re-run `php artisan migrate` if needed

**Note:** Database migrations cannot always be automatically reversed. Always backup the database before deployment.

---

## Security Checklist

- [ ] `APP_DEBUG=false` in production
- [ ] `.env` is NOT inside the public directory
- [ ] Strong `JWT_SECRET` configured
- [ ] Bank credentials are NOT hard-coded
- [ ] CORS is restricted to production domain
- [ ] File upload validation is active
- [ ] Rate limiting is configured
