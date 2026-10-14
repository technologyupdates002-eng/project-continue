#!/bin/bash
cd /home/kirinyag/public_html/app1.kirinyagahealthcareworkerswelfare.co.ke/khwwc

echo "=== RESTART QUEUE WORKER ==="
pkill -f "artisan queue:work" || true
sleep 2
nohup php artisan queue:work --queue=default --sleep=3 --tries=3 --timeout=60 > storage/logs/queue-worker.log 2>&1 &
sleep 2
ps aux | grep "queue:work" | grep -v grep || echo "Worker may still be starting..."

echo ""
echo "=== VERIFY REDIS ==="
php artisan tinker --execute="try { echo Redis::ping(); } catch (Throwable \$e) { echo \"Error: \" . \$e->getMessage(); }" 2>/dev/null || echo "Redis check failed"

echo ""
echo "=== VERIFY QUEUE CONNECTION ==="
php artisan tinker --execute="echo config('queue.default');" 2>/dev/null || echo "Queue config check failed"
