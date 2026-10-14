#!/bin/bash
set -e

# Production Emergency Deployment Script
# Target: app1.kirinyagahealthcareworkerswelfare.co.ke
# Emergency issues fixed: Online users, heartbeat, presence config

TARGET_HOST="169.58.129.32"
TARGET_PORT="6622"
TARGET_USER="kirinyag"
SSH_KEY="$HOME/.ssh/puty_openssh"
APP_PATH="/home/kirinyag/public_html/app1.kirinyagahealthcareworkerswelfare.co.ke/khwwc"
BACKUP_DIR="/home/kirinyag/public_html/app1.kirinyagahealthcareworkerswelfare.co.ke/khwwc/.backups/emergency-$(date +%Y%m%d-%H%M%S)"

echo "==================================="
echo "Production Emergency Deployment"
echo "==================================="
echo "Target: $TARGET_HOST:$TARGET_PORT"
echo "App: $APP_PATH"
echo ""

# Verify SSH connection
echo "[1/6] Verifying SSH connection..."
ssh -i "$SSH_KEY" -p "$TARGET_PORT" "$TARGET_USER@$TARGET_HOST" "echo 'SSH connection verified' && pwd"

# Create backup directory
echo "[2/6] Creating backup directory..."
ssh -i "$SSH_KEY" -p "$TARGET_PORT" "$TARGET_USER@$TARGET_HOST" "mkdir -p $BACKUP_DIR"

# Backup production files
echo "[3/6] Backing up production files..."
ssh -i "$SSH_KEY" -p "$TARGET_PORT" "$TARGET_USER@$TARGET_HOST" \
  "cp $APP_PATH/app/Http/Controllers/AuthController.php $BACKUP_DIR/ && \
   cp $APP_PATH/app/Http/Controllers/Api/PresenceController.php $BACKUP_DIR/ && \
   echo 'Backup created at: $BACKUP_DIR'"

# Deploy AuthController.php
echo "[4/6] Deploying AuthController.php..."
scp -P "$TARGET_PORT" -i "$SSH_KEY" \
  "app/Http/Controllers/AuthController.php" \
  "$TARGET_USER@$TARGET_HOST:$APP_PATH/app/Http/Controllers/"

# Deploy PresenceController.php
echo "[5/6] Deploying PresenceController.php..."
scp -P "$TARGET_PORT" -i "$SSH_KEY" \
  "app/Http/Controllers/Api/PresenceController.php" \
  "$TARGET_USER@$TARGET_HOST:$APP_PATH/app/Http/Controllers/Api/"

# Clear Laravel caches
echo "[6/6] Clearing Laravel caches..."
ssh -i "$SSH_KEY" -p "$TARGET_PORT" "$TARGET_USER@$TARGET_HOST" \
  "cd $APP_PATH && php artisan config:clear && php artisan route:clear && echo 'Caches cleared'"

echo ""
echo "==================================="
echo "Deployment completed successfully!"
echo "Backup location: $BACKUP_DIR"
echo "==================================="
echo ""
echo "Next steps:"
echo "1. Run production smoke tests"
echo "2. Monitor application logs for errors"
echo "3. Verify online users endpoint returns all eligible members"
echo "4. Verify heartbeat requests succeed"
