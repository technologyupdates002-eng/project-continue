# KHWWC cPanel/LiteSpeed Deployment Guide

## Prerequisites

- cPanel access with terminal/SSH
- Domain: `app1.kirinyagahealthcareworkerswelfare.co.ke`
- Document root must point to: `/home/kirinyag/public_html/app1.kirinyagahealthcareworkerswelfare.co.ke/khwwc/public`

---

## Step 1: Upload and Extract

Upload `khwwc_deploy_package_20260830.zip` to:
```
/home/kirinyag/public_html/app1.kirinyagahealthcareworkerswelfare.co.ke/
```

Extract via cPanel File Manager or terminal:
```bash
cd /home/kirinyag/public_html/app1.kirinyagahealthcareworkerswelfare.co.ke/
unzip khwwc_deploy_package_20260830.zip
```

**Verify structure:**
```bash
ls -la /home/kirinyag/public_html/app1.kirinyagahealthcareworkerswelfare.co.ke/khwwc/public/
# Should show: index.php, .htaccess, favicon.ico, etc.
```

---

## Step 2: Fix 500 Error Checklist

A 500 error after extraction is almost always one of these:

### A. Missing vendor/ directory
If `vendor/` is not present, the application cannot run.

**Option 1: Install Composer dependencies (if Composer is available)**
```bash
cd /home/kirinyag/public_html/app1.kirinyagahealthcareworkerswelfare.co.ke/khwwc
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
```

**Option 2: Use the deployment script**
```bash
bash cpanel_deploy.sh
```
The script will attempt composer install automatically.

### B. Missing .env file
```bash
cd /home/kirinyag/public_html/app1.kirinyagahealthcareworkerswelfare.co.ke/khwwc
cp .env.production.example .env
```

### C. Missing APP_KEY
```bash
cd /home/kirinyag/public_html/app1.kirinyagahealthcareworkerswelfare.co.ke/khwwc
php artisan key:generate --force
```

### D. Wrong permissions
```bash
chmod -R 775 /home/kirinyag/public_html/app1.kirinyagahealthcareworkerswelfare.co.ke/khwwc/storage
chmod -R 775 /home/kirinyag/public_html/app1.kirinyagahealthcareworkerswelfare.co.ke/khwwc/bootstrap/cache
```

### E. Document root misconfigured
In cPanel → **Domains** → **Domain Manager**:
- Domain: `app1.kirinyagahealthcareworkerswelfare.co.ke`
- Document root: `/home/kirinyag/public_html/app1.kirinyagahealthcareworkerswelfare.co.ke/khwwc/public`

**NOT** `/home/kirinyag/public_html/app1.kirinyagahealthcareworkerswelfare.co.ke/khwwc/`

---

## Step 3: Run Automated Deployment Script

```bash
cd /home/kirinyag/public_html/app1.kirinyagahealthcareworkerswelfare.co.ke/khwwc
bash cpanel_deploy.sh
```

This script will:
1. Verify PHP 8.4
2. Install composer dependencies if needed
3. Create `.env` from template
4. Generate `APP_KEY`
5. Set storage permissions
6. Create storage symlink
7. Run database migrations
8. Clear and cache Laravel configuration
9. Verify the health endpoint

---

## Step 4: Troubleshooting 500 Errors

If you still see 500 after running the script:

### Check error logs
```bash
# cPanel error log
tail -f ~/logs/error_log

# Laravel log
tail -f /home/kirinyag/public_html/app1.kirinyagahealthcareworkerswelfare.co.ke/khwwc/storage/logs/laravel.log
```

### Common issues

| Error | Fix |
|-------|-----|
| `Class 'PDO' not found` | Enable PHP pdo_mysql extension in cPanel |
| `No application encryption key` | Run `php artisan key:generate --force` |
| `SQLSTATE[HY000] [2002]` | Check DB credentials in `.env`, verify MySQL is running |
| `Permission denied` | `chmod -R 775 storage/ bootstrap/cache/` |
| `vendor/autoload.php missing` | Run `composer install` or rebuild package with vendor/ |
| `Memory exhausted` | Increase PHP memory limit in cPanel PHP Selector |

### Verify PHP settings in cPanel
1. Go to **cPanel** → **PHP Selector** (or **Select PHP Version**)
2. Ensure PHP 8.4 is selected for the domain
3. Enable extensions: `pdo_mysql`, `mbstring`, `openssl`, `curl`, `json`, `tokenizer`, `xml`
4. Set `memory_limit = 256M` or higher

---

## Step 5: Post-Deployment

1. Visit: `https://app1.kirinyagahealthcareworkerswelfare.co.ke/api/health`
2. Log in as Super Admin
3. Change default passwords
4. Configure bank credentials in `.env` if not already done
5. Test bank connectivity from **Bank Diagnostics** page

---

## Important Notes

- The deployment package does NOT include `vendor/` to keep the ZIP size manageable
- You MUST run `composer install` or use `cpanel_deploy.sh` before the app will work
- Never commit `.env` to version control
- The `deployment/bank/` directory contains the Postman test collection (TEST credentials only)
