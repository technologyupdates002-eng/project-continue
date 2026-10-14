#!/bin/bash
set -euo pipefail

# =============================================================================
# Extract Deployment ZIP
# =============================================================================
# Usage: bash extract.sh
# =============================================================================

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ZIP_FILE="${SCRIPT_DIR}/KHWWC_PRODUCTION_DEPLOYMENT_FINAL_20260831.zip"
APP_DIR="${SCRIPT_DIR}/khwwc"

echo "============================================"
echo " Extract Deployment Package"
echo "============================================"
echo ""

if [ ! -f "$ZIP_FILE" ]; then
    echo "ERROR: ZIP file not found: $ZIP_FILE"
    echo "Please upload KHWWC_PRODUCTION_DEPLOYMENT_FINAL_20260831.zip first"
    exit 1
fi

if [ -d "$APP_DIR" ]; then
    echo "WARNING: $APP_DIR already exists"
    read -p "Remove and re-extract? (y/N): " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        BACKUP="${APP_DIR}_backup_$(date +%Y%m%d_%H%M%S)"
        echo "Backing up to: $BACKUP"
        mv "$APP_DIR" "$BACKUP"
    else
        echo "Aborted"
        exit 1
    fi
fi

echo "Extracting..."
mkdir -p "$APP_DIR"
unzip -q "$ZIP_FILE" -d "$SCRIPT_DIR"

if [ ! -d "$APP_DIR" ]; then
    echo "ERROR: Extraction failed"
    exit 1
fi

echo ""
echo "Extracted to: $APP_DIR"
echo ""
echo "Contents:"
ls -la "$APP_DIR" | head -20

echo ""
echo "============================================"
echo " Extraction Complete"
echo "============================================"
echo ""
echo "Next steps:"
echo "  1. Set document root to: $APP_DIR/public"
echo "  2. Run: bash cpanel_deploy.sh"
