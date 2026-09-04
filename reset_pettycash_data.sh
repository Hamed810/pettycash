#!/bin/bash

set -e

echo "WARNING: This will delete ALL PettyCash application data."
echo "Schema and migrations will remain."
read -p "Continue? (yes/no): " confirm

if [ "$confirm" != "yes" ]; then
    echo "Cancelled."
    exit 0
fi


echo "Enabling maintenance mode..."

docker exec -u www-data docker-nextcloud-1 \
php occ maintenance:mode --on


echo "Cleaning PettyCash tables..."


docker exec -i docker-db-1 \
mariadb -u nextcloud -pnextcloud nextcloud <<'SQL'

SET FOREIGN_KEY_CHECKS=0;


TRUNCATE TABLE oc_pcash_action;

TRUNCATE TABLE oc_pcash_attach;

TRUNCATE TABLE oc_pcash_audit;

TRUNCATE TABLE oc_pcash_revision;

TRUNCATE TABLE oc_pcash_txn;

TRUNCATE TABLE oc_pcash_list;

TRUNCATE TABLE oc_pcash_vehicle;

TRUNCATE TABLE oc_pcash_member;

TRUNCATE TABLE oc_pcash_project;

TRUNCATE TABLE oc_pcash_ocr;


TRUNCATE TABLE oc_pcash_category;

TRUNCATE TABLE oc_pcash_currency;


SET FOREIGN_KEY_CHECKS=1;

SQL


echo "Repairing Nextcloud..."

docker exec -u www-data docker-nextcloud-1 \
php occ maintenance:repair


echo "Disabling maintenance mode..."

docker exec -u www-data docker-nextcloud-1 \
php occ maintenance:mode --off


echo ""
echo "PettyCash data reset completed."
