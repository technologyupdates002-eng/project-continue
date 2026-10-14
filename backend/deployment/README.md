# Deployment Scripts

## Quick Start (cPanel Terminal)

```bash
cd /home/kirinyag/public_html/app1.kirinyagahealthcareworkerswelfare.co.ke
bash extract.sh
cd khwwc
bash cpanel_deploy.sh
```

## Scripts

| Script | Purpose |
|--------|---------|
| `cpanel_deploy.sh` | Master deployment - runs all steps |
| `extract.sh` | Extract the ZIP package |
| `fix-permissions.sh` | Set Laravel permissions |
| `generate_jwt.sh` | Generate JWT secret |
| `run_migrations.sh` | Run database migrations |
| `health-check.sh` | Verify health endpoint |

## Prerequisites

- PHP 8.4
- MySQL 8.0+
- Composer (if vendor/ missing)
- SSH access to cPanel

## Document Root

```
/home/kirinyag/public_html/app1.kirinyagahealthcareworkerswelfare.co.ke/khwwc/public
```

## Environment

Copy `.env.example` to `.env` and configure:
- Database credentials
- JWT_SECRET
- Co-operative Bank API credentials
- APP_KEY (run `php artisan key:generate`)

## Admin Credentials

| Role | Email | Password |
|------|-------|----------|
| Super Admin | `superadmin@khwwc.local` | `Super2026` |
| Admin | `admin@khwwc.local` | `Admin2026` |
| Treasurer | `treasurer@khwwc.local` | `Treasurer2026` |
| Secretary | `secretary@khwwc.local` | `Secretary2026` |

**CHANGE THESE IN PRODUCTION!**
