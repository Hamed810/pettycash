#!/bin/bash

set -e

APP_DIR="$HOME/projects/pettycash"
BACKUP_DIR="$HOME/pettycash_backup_v041_$(date +%Y%m%d_%H%M%S)"

echo "====================================="
echo "PettyCash v0.4.1 Preparation"
echo "====================================="

cd "$APP_DIR"

echo ""
echo "1. Creating backup..."
mkdir -p "$BACKUP_DIR"

cp -r \
    appinfo \
    lib \
    src \
    templates \
    l10n \
    composer.json \
    package.json \
    "$BACKUP_DIR"/

echo "Backup created:"
echo "$BACKUP_DIR"


echo ""
echo "2. Checking current structure..."

DIRS="
lib/Service
lib/Db
lib/Controller
lib/Migration
src/views
src/components
src/services
"


for d in $DIRS
do
    if [ -d "$d" ]; then
        echo "OK $d"
    else
        echo "Missing $d"
    fi
done


echo ""
echo "3. Creating v0.4.1 documentation folder..."

mkdir -p docs

cat > docs/VERSION_0.4.1_PLAN.md <<EOF
# Petty Cash v0.4.1

Scope:

- Separate dashboard and administration
- Add admin settings
- Nextcloud user integration
- Multiple open cost lists
- Delete open cost lists
- Currency administration
- Expense category administration
- Vehicle rules
- Employee document rules
- Approval workflow configuration
- OCR configuration

Status:
Development
EOF


echo ""
echo "4. Creating planned backend files..."

FILES="
lib/Service/AdminSettingsService.php
lib/Service/UserService.php
lib/Service/SettingsService.php
lib/Controller/AdminController.php
"


for f in $FILES
do
    if [ ! -f "$f" ]; then
        echo "Creating placeholder $f"
        mkdir -p "$(dirname "$f")"
        touch "$f"
    else
        echo "Exists $f"
    fi
done


echo ""
echo "5. Creating frontend folders..."

mkdir -p src/views/admin
mkdir -p src/components/admin
mkdir -p src/services


echo ""
echo "6. Creating change tracking file..."

cat > V041_CHANGED_FILES.md <<EOF
# Petty Cash v0.4.1 Changed Files

## Backend

- Pending

## Frontend

- Pending

## Database migrations

- Pending

## Configuration

- Pending

EOF


echo ""
echo "====================================="
echo "Preparation completed"
echo "====================================="

echo ""
echo "Next steps:"
echo "1. Review current database entities"
echo "2. Design migration"
echo "3. Implement Admin Settings"
echo "4. Implement Nextcloud user picker"
echo "5. Modify CostList workflow"
