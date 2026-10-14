# cPanel Terminal Deployment Guide

## Your Directory Structure

```
/home/kirinyag/public_html/app1.kirinyagahealthcareworkerswelfare.co.ke/khwwc/public/
```

The document root is already set to `khwwc/public`.

## Step 1: Upload ZIP

Upload `KHWWC_PRODUCTION_DEPLOYMENT_FINAL_20260831.zip` to:
```
/home/kirinyag/public_html/app1.kirinyagahealthcareworkerswelfare.co.ke/
```

## Step 2: SSH and Extract

```bash
cd /home/kirinyag/public_html/app1.kirinyagahealthcareworkerswelfare.co.ke
bash extract.sh
```

This extracts to `./khwwc/`

## Step 3: Configure Environment

```bash
cd khwwc
nano .env
```

Update these values:
- `APP_KEY` (run: `php artisan key:generate`)
- `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`
- `JWT_SECRET` (or run: `bash generate_jwt.sh`)
- `COOP_CONSUMER_KEY`, `COOP_CONSUMER_SECRET`
- `COOP_ACCOUNT_NUMBER`

## Step 4: Run Deployment

```bash
bash cpanel_deploy.sh
```

This will:
1. Verify PHP
2. Extract package (if not done)
3. Set up .env
4. Generate JWT secret
5. Generate APP_KEY
6. Fix permissions
7. Install Composer dependencies (if needed)
8. Run migrations
9. Seed admin users
10. Clear and cache config
11. Run health check

## Step 5: Verify

```bash
bash health-check.sh
```

Or visit: `https://app1.kirinyagahealthcareworkerswelfare.co.ke/api/health`

## Step 6: Test Login

- Super Admin: `superadmin@khwwc.local` / `Super2026`
- Admin: `admin@khwwc.local` / `Admin2026`

## Manual Commands (if needed)

```bash
cd /home/kirinyag/public_html/app1.kirinyagahealthcareworkerswelfare.co.ke/khwwc

# Fix permissions
bash fix-permissions.sh

# Run migrations only
bash run_migrations.sh

# Generate JWT
bash generate_jwt.sh

# Generate APP_KEY
php artisan key:generate --force

# Seed admin
php artisan db:seed --class=ProductionAdminSeeder --force

# Clear cache
php artisan optimize:clear

# Check logs
tail -f storage/logs/laravel.log
```

## Important Notes

1. **DO NOT** run `migrate:fresh` on production
2. **CHANGE** default passwords after first login
3. **VERIFY** document root points to `khwwc/public`
4. **ENSURE** `.env` is NOT accessible via web
5. **CHECK** SSL certificate is valid

## Troubleshooting

### 500 Internal Server Error
```bash
tail -f storage/logs/laravel.log
```

### Permission Denied
```bash
bash fix-permissions.sh
```

### Database Connection Error
```bash
# Test connection
php artisan db:show
```

### JWT Errors
```bash
bash generate_jwt.sh
php artisan config:clear
```

### White Screen / SPA not loading
- Verify document root is `khwwc/public`
- Verify `.htaccess` exists in `public/`
- Verify `public/dist/index.html` exists
