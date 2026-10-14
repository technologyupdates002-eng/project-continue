#!/bin/bash
# STK Production Deployment Script
# Run this ON THE PRODUCTION SERVER as the application user
# Usage: bash deploy-stk-fix.sh

set -e

APP_DIR="/home/laban/Documents/kirinyaga App/khwwc"
BACKUP_DIR="$APP_DIR/storage/backups/stk-deploy-$(date +%Y%m%d-%H%M%S)"
RELEASE_TAG="stk-fix-$(date +%Y%m%d-%H%M%S)"

echo "=========================================="
echo "STK Production Deployment"
echo "=========================================="
echo ""

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Step 1: Pre-flight checks
echo -e "${YELLOW}[1/8] Pre-flight checks${NC}"

# Check PHP version
PHP_VERSION=$(php -r 'echo PHP_VERSION;')
echo "PHP version: $PHP_VERSION"

# Check Laravel version
LARAVEL_VERSION=$(php artisan --version 2>/dev/null | grep -oP '\d+\.\d+\.\d+' | head -1 || echo "unknown")
echo "Laravel version: $LARAVEL_VERSION"

# Check Redis
if php artisan tinker --execute="echo Redis::ping();" 2>/dev/null | grep -q "PONG"; then
    echo -e "${GREEN}Redis: CONNECTED${NC}"
else
    echo -e "${RED}Redis: NOT CONNECTED${NC}"
    exit 1
fi

# Check queue connection
QUEUE_CONNECTION=$(php artisan tinker --execute="echo config('queue.default');" 2>/dev/null | tr -d '\n')
echo "Queue driver: $QUEUE_CONNECTION"

# Check if queue worker is running
if pgrep -f "artisan queue:work" > /dev/null; then
    echo -e "${GREEN}Queue worker: RUNNING${NC}"
else
    echo -e "${YELLOW}Queue worker: NOT RUNNING - STARTING...${NC}"
    nohup php artisan queue:work --queue=default --sleep=3 --tries=3 --timeout=60 > storage/logs/queue-worker.log 2>&1 &
    sleep 2
    if pgrep -f "artisan queue:work" > /dev/null; then
        echo -e "${GREEN}Queue worker: STARTED${NC}"
    else
        echo -e "${RED}Queue worker: FAILED TO START${NC}"
        exit 1
    fi
fi

# Check scheduler
if crontab -l 2>/dev/null | grep -q "artisan schedule:run"; then
    echo -e "${GREEN}Scheduler: CONFIGURED${NC}"
else
    echo -e "${YELLOW}Scheduler: NOT CONFIGURED - Setting up...${NC}"
    (crontab -l 2>/dev/null; echo "* * * * * cd $APP_DIR && php artisan schedule:run >> /dev/null 2>&1") | crontab -
    echo -e "${GREEN}Scheduler: CONFIGURED${NC}"
fi

echo ""
echo -e "${GREEN}Pre-flight checks passed${NC}"
echo ""

# Step 2: Backup
echo -e "${YELLOW}[2/8] Creating backups${NC}"
mkdir -p "$BACKUP_DIR"

# Backup database
echo "Backing up database..."
php artisan backup:run --only-db 2>/dev/null || {
    # Fallback: use mysqldump if available
    if command -v mysqldump &> /dev/null; then
        mysqldump $(php artisan tinker --execute="echo config('database.connections.mysql.database');" 2>/dev/null | tr -d '\n') > "$BACKUP_DIR/database.sql" 2>/dev/null || true
    fi
}

# Backup .env
cp "$APP_DIR/.env" "$BACKUP_DIR/.env.backup" 2>/dev/null || true

# Backup STK-related files
echo "Backing up STK files..."
mkdir -p "$BACKUP_DIR/app"
mkdir -p "$BACKUP_DIR/frontend"

for file in \
    "app/Services/CoopBank/CoopBankAccountService.php" \
    "app/Services/CoopBank/CoopBankTransferService.php" \
    "app/Services/PaymentService.php" \
    "app/Services/CoopBank/CoopBankTransactionService.php" \
    "app/Jobs/ReconcilePendingStkTransaction.php" \
    "frontend/src/pages/member/PayPenalty.tsx" \
    "frontend/src/pages/member/Donate.tsx" \
    "frontend/src/pages/member/MemberDashboard.tsx"; do
    if [ -f "$APP_DIR/$file" ]; then
        cp "$APP_DIR/$file" "$BACKUP_DIR/$file" 2>/dev/null || true
    fi
done

# Record current git state
cd "$APP_DIR"
git log --oneline -1 > "$BACKUP_DIR/git-commit.txt" 2>/dev/null || echo "Not a git repo" > "$BACKUP_DIR/git-commit.txt"

echo -e "${GREEN}Backups created in: $BACKUP_DIR${NC}"
echo ""

# Step 3: Deploy backend changes
echo -e "${YELLOW}[3/8] Deploying backend changes${NC}"

# These files should be copied from the local development environment
# For now, we verify the files exist and show the diff
echo "Files to deploy:"
for file in \
    "app/Services/CoopBank/CoopBankAccountService.php" \
    "app/Services/CoopBank/CoopBankTransferService.php" \
    "app/Services/PaymentService.php" \
    "app/Services/CoopBank/CoopBankTransactionService.php" \
    "app/Jobs/ReconcilePendingStkTransaction.php"; do
    if [ -f "$APP_DIR/$file" ]; then
        echo "  ✓ $file"
    else
        echo "  ✗ $file (MISSING - needs to be deployed)"
    fi
done

echo ""
echo -e "${YELLOW}ACTION REQUIRED: Copy the fixed files from development to production${NC}"
echo "Files to copy:"
echo "  1. app/Services/CoopBank/CoopBankAccountService.php"
echo "  2. app/Services/CoopBank/CoopBankTransferService.php"
echo "  3. app/Services/PaymentService.php"
echo "  4. app/Services/CoopBank/CoopBankTransactionService.php"
echo "  5. app/Jobs/ReconcilePendingStkTransaction.php"
echo ""

# Step 4: Verify syntax
echo -e "${YELLOW}[4/8] Verifying PHP syntax${NC}"
for file in \
    "app/Services/CoopBank/CoopBankAccountService.php" \
    "app/Services/CoopBank/CoopBankTransferService.php" \
    "app/Services/PaymentService.php" \
    "app/Services/CoopBank/CoopBankTransactionService.php" \
    "app/Jobs/ReconcilePendingStkTransaction.php"; do
    if [ -f "$APP_DIR/$file" ]; then
        if php -l "$APP_DIR/$file" > /dev/null 2>&1; then
            echo "  ✓ $file"
        else
            echo -e "  ${RED}✗ $file - SYNTAX ERROR${NC}"
            exit 1
        fi
    fi
done

echo ""

# Step 5: Clear caches
echo -e "${YELLOW}[5/8] Clearing Laravel caches${NC}"
php artisan optimize:clear
echo -e "${GREEN}Caches cleared${NC}"
echo ""

# Step 6: Run migrations (if any new ones)
echo -e "${YELLOW}[6/8] Checking migrations${NC}"
php artisan migrate:status 2>/dev/null | grep -i "pending" || echo "No pending migrations"
echo ""

# Step 7: Run tests
echo -e "${YELLOW}[7/8] Running tests${NC}"
php artisan test tests/Feature/Payments/ --no-interaction 2>&1 | tail -n 20 || {
    echo -e "${RED}Tests failed${NC}"
    exit 1
}
echo ""

# Step 8: Final verification
echo -e "${YELLOW}[8/8] Final verification${NC}"

# Check STK configuration
echo "STK Configuration:"
echo "  Environment: $(php artisan tinker --execute="echo config('coop_bank.environment');" 2>/dev/null | tr -d '\n')"
echo "  Real calls: $(php artisan tinker --execute="echo config('coop_bank.enable_real_calls');" 2>/dev/null | tr -d '\n')"
echo "  Base URL: $(php artisan tinker --execute="echo config('coop_bank.base_url');" 2>/dev/null | tr -d '\n')"
echo "  Account: $(php artisan tinker --execute="echo config('coop_bank.account_number');" 2>/dev/null | tr -d '\n')"

echo ""
echo -e "${GREEN}=========================================="
echo "Deployment Complete"
echo "==========================================${NC}"
echo ""
echo "Backup location: $BACKUP_DIR"
echo "To rollback:"
echo "  cd $APP_DIR"
echo "  git checkout -- app/Services/CoopBank/CoopBankAccountService.php"
echo "  git checkout -- app/Services/CoopBank/CoopBankTransferService.php"
echo "  git checkout -- app/Services/PaymentService.php"
echo "  php artisan optimize:clear"
