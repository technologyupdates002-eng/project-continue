# Production Verification Checklist

## Pre-Deployment

- [ ] PHP 8.4 verified on server
- [ ] MySQL 8.0+ verified on server
- [ ] Domain DNS points to server
- [ ] SSL certificate installed and valid
- [ ] `.env` configured with production values
- [ ] `APP_KEY` generated
- [ ] Database created and user has full privileges
- [ ] Document root set to `khwwc/public`

## Post-Deployment

- [ ] `/api/health` returns 200 OK
- [ ] SPA loads at `/`
- [ ] Deep route `/login` loads directly
- [ ] Deep route `/dashboard` loads after login
- [ ] Deep route `/admin` loads for admin
- [ ] Deep route `/super-admin` loads for super admin
- [ ] No browser console critical errors
- [ ] No 404s on SPA routes after refresh

## Authentication

- [ ] Super Admin login works
- [ ] Admin login works
- [ ] Treasurer login works
- [ ] Secretary login works
- [ ] Member login works
- [ ] Logout works
- [ ] Invalid password rejected
- [ ] Unauthorized route returns 401

## Dashboards

- [ ] Admin dashboard loads
- [ ] Super Admin dashboard loads
- [ ] Treasurer dashboard loads
- [ ] Chairperson dashboard loads
- [ ] Secretary dashboard loads
- [ ] Member dashboard loads

## Core Features

- [ ] Member list loads (Super Admin)
- [ ] Member creation works
- [ ] Member edit works
- [ ] Password change works
- [ ] Contribution history loads
- [ ] Pay Now button visible for members
- [ ] Transaction history loads

## Bank Integration

- [ ] Bank diagnostic page loads
- [ ] Bank authentication test passes
- [ ] STK Push initiates successfully
- [ ] Callback endpoint reachable (`/api/banking/coop/callback`)
- [ ] Transaction status updates after callback
- [ ] No duplicate callbacks double-credit

## Imports

- [ ] Beneficiary Excel import works
- [ ] Bank statement import works
- [ ] Import summary reports errors correctly

## Administration

- [ ] Audit logs page loads
- [ ] Backup creation works
- [ ] Backup list loads
- [ ] Settings page loads

## Security

- [ ] No Supabase requests in browser network tab
- [ ] No hard-coded secrets in frontend source
- [ ] `.env` not accessible via web
- [ ] `app/`, `config/`, `vendor/` not accessible via web
- [ ] HTTPS enforced
- [ ] HSTS header present

## Performance

- [ ] Dashboard loads in < 3 seconds
- [ ] Member list pagination works
- [ ] Search/filter works
