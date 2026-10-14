# Upgrading the live server (portal endpoints + /api/v1)

1. **Back up** the database (cPanel → phpMyAdmin → Export) and the current `public_html`/app folder.
2. Upload these changed/new files from `backend/` over the live app:
   - `routes/api.php`, `routes/portal.php`
   - `app/Http/Controllers/Api/V1/*`
   - `app/Http/Controllers/PaymentController.php`
   - `app/Providers/AppServiceProvider.php`, `app/Observers/FinancialChangeObserver.php`
   - `database/migrations/2026_10_14_210000_add_portal_performance_indexes.php`
   - any new `app/Services`, `app/Jobs` files from this project
3. In cPanel Terminal, inside the app folder:
   ```
   php artisan migrate --force
   php artisan optimize:clear
   php artisan route:list --path=v1 | head
   php artisan config:cache && php artisan route:cache
   ```
4. Make sure a queue worker runs (bulk SMS sends in batches). cPanel → Cron Jobs, every minute:
   `php /home/USER/APP/artisan queue:work --stop-when-empty --max-time=55`
   (keep the existing `schedule:run` cron).
5. Smoke test: log in as a member, open the dashboard, start a small payment, confirm the balance updates; log in as treasurer and admin and open each dashboard.
6. Rollback: restore the backup files, then `php artisan migrate:rollback --step=1`.
