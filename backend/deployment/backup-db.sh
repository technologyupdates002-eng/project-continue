#!/bin/bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
cd "$PROJECT_ROOT"

TIMESTAMP=$(date +%Y%m%d_%H%M%S)
BACKUP_DIR="$PROJECT_ROOT/storage/app/backups"
mkdir -p "$BACKUP_DIR"

# Load DB credentials from .env
DB_HOST=$(php -r "echo env('DB_HOST');" 2>/dev/null || echo "127.0.0.1")
DB_PORT=$(php -r "echo env('DB_PORT');" 2>/dev/null || echo "3306")
DB_DATABASE=$(php -r "echo env('DB_DATABASE');" 2>/dev/null || echo "")
DB_USERNAME=$(php -r "echo env('DB_USERNAME');" 2>/dev/null || echo "root")
DB_PASSWORD=$(php -r "echo env('DB_PASSWORD');" 2>/dev/null || echo "")

if [ -z "$DB_DATABASE" ]; then
    echo "ERROR: DB_DATABASE not configured in .env"
    exit 1
fi

BACKUP_FILE="$BACKUP_DIR/backup_${TIMESTAMP}.sql"

echo "============================================"
echo " Database Backup"
echo "============================================"
echo "Database: $DB_DATABASE"
echo "Host: $DB_HOST:$DB_PORT"
echo "Output: $BACKUP_FILE"
echo ""

if command -v mysqldump &> /dev/null; then
    MYSQLDUMP_OPTS="--single-transaction --routines --triggers --events"
    if [ -n "$DB_PASSWORD" ]; then
        mysqldump $MYSQLDUMP_OPTS -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" > "$BACKUP_FILE"
    else
        mysqldump $MYSQLDUMP_OPTS -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" "$DB_DATABASE" > "$BACKUP_FILE"
    fi
else
    echo "ERROR: mysqldump not found. Install MySQL client tools."
    exit 1
fi

if [ -f "$BACKUP_FILE" ]; then
    SIZE=$(du -h "$BACKUP_FILE" | cut -f1)
    echo "Backup created: $BACKUP_FILE ($SIZE)"
else
    echo "ERROR: Backup file not created"
    exit 1
fi

echo ""
echo "============================================"
echo " Backup Complete"
echo "============================================"
