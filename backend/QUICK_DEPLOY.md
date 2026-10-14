# cPanel Deployment - Quick Start

## Your Situation
- You're already logged into the server
- Your directory is: `/home/kirinyag/public_html/app1.kirinyagahealthcareworkerswelfare.co.ke/`
- Document root is already set to `khwwc/public`

## Step 1: Upload Files via cPanel File Manager

Upload these TWO files to:
```
/home/kirinyag/public_html/app1.kirinyagahealthcareworkerswelfare.co.ke/
```

1. `KHWWC_PRODUCTION_DEPLOYMENT_FINAL_20260831.zip`
2. `deploy.sh` (single-file deployment script)

## Step 2: Run in cPanel Terminal

```bash
cd /home/kirinyag/public_html/app1.kirinyagahealthcareworkerswelfare.co.ke
bash deploy.sh
```

That's it. The script will:
1. Extract the ZIP
2. Create .env from .env.example
3. Generate JWT_SECRET
4. Generate APP_KEY
5. Fix permissions
6. Run migrations
7. Seed admin users
8. Optimize for production

## Step 3: Configure .env (if needed)

```bash
nano /home/kirinyag/public_html/app1.kirinyagahealthcareworkerswelfare.co.ke/khwwc/.env
```

Update:
- `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`
- `COOP_CONSUMER_KEY`, `COOP_CONSUMER_SECRET`
- `COOP_ACCOUNT_NUMBER`

## Step 4: Verify

```bash
curl https://app1.kirinyagahealthcareworkerswelfare.co.ke/api/health
```

Or visit in browser.

## Admin Login

- Super Admin: `superadmin@khwwc.local` / `Super2026`
- Admin: `admin@khwwc.local` / `Admin2026`

**CHANGE THESE PASSWORDS AFTER FIRST LOGIN!**

## Troubleshooting

### Permission denied
```bash
bash fix-permissions.sh
```

### Database error
```bash
cd khwwc
php artisan db:show
nano .env
```

### 500 error
```bash
cd khwwc
tail -f storage/logs/laravel.log
```

### White screen
- Verify document root is `khwwc/public`
- Verify `.htaccess` exists in `public/`
