This document records the lessons learned from this project and the rules I should follow when assisting you in future changes.

Nextcloud PettyCash Development Rules & Lessons Learned

Project: PettyCash Nextcloud App
Environment: Nextcloud 34.x
Database: MariaDB
Framework: Nextcloud AppFramework + Vue 3 + TypeScript
Purpose: Permanent development rules and lessons learned

1. Nextcloud Database Migration Rules
1.1 Never manually add the database prefix

Nextcloud database abstraction automatically handles the table prefix.

Correct:

$qb = $this->db->getQueryBuilder();

$qb->insert('pcash_currency')

The final database table becomes:

oc_pcash_currency

depending on the installation prefix.

Incorrect:

$qb->insert('oc_pcash_currency')

Result:

oc_oc_pcash_currency

This will fail.

Incorrect:

$prefix = $this->db->getPrefix();

Do not use this with Nextcloud 34 IDBConnection.

The connection adapter does not expose:

getPrefix()
1.2 Existing project migration style is the source of truth

Before creating a new migration:

Always inspect existing migrations:

Example:

lib/Migration/Version0200Date20260901234500.php

If existing migrations use:

insert('pcash_currency')

all future migrations must follow the same pattern.

Do not introduce a different database style.

2. Migration Version Rules

Migration versions must always increase.

Example:

0100Date20260901230000.php
0200Date20260901234500.php
0300Date20260904000000.php
0310Date20260904014500.php
0400Date20260904000000.php

Never reuse an already registered migration.

Check:

SELECT *
FROM oc_migrations
WHERE app='pettycash'
ORDER BY version;
3. Migration Testing Procedure

Before running:

Syntax check

Inside container:

docker exec -u www-data docker-nextcloud-1 \
php -l /var/www/html/custom_apps/pettycash/lib/Migration/FILENAME.php

Expected:

No syntax errors detected
Run upgrade
docker exec -u www-data docker-nextcloud-1 \
php occ upgrade
Verify migration registration
docker exec -it docker-db-1 \
mariadb -u nextcloud -pnextcloud nextcloud \
-e "SELECT * FROM oc_migrations WHERE app='pettycash' ORDER BY version;"
4. Database Naming Rules

PettyCash tables:

pcash_currency
pcash_category
pcash_project
pcash_member
pcash_list
pcash_txn
pcash_revision
pcash_action
pcash_attach
pcash_vehicle
pcash_audit
pcash_ocr

Inside PHP:

Use:

pcash_currency

Never:

oc_pcash_currency
5. Entity and Mapper Rules

Every database entity must have:

Entity
Mapper
Service
Controller
Frontend API
Vue Component

Example:

Currency:

Db/Currency.php
Db/CurrencyMapper.php
Service/CurrencyService.php
Controller/CurrencyController.php
src/services/api.ts
6. Mapper Rules

If services call:

$this->mapper->find($id)

the mapper must contain:

public function find(int $id): Entity

Before adding a service feature:

Search:

grep -R -- "->find(" lib/Service

Then verify every mapper supports that method.

7. API Route Rules

This project uses PHP attributes.

Example:

#[ApiRoute(
    verb:'GET',
    url:'/api/v1/projects'
)]

Do not create:

appinfo/routes.php

unless required.

Routes are discovered from controller attributes.

Verify:

docker exec -u www-data docker-nextcloud-1 \
php occ router:list | grep pettycash
8. OCS API Testing

PettyCash API uses OCS routes.

Correct:

/ocs/v2.php/apps/pettycash/api/v1/...

Example:

curl \
-u admin:admin \
-H "OCS-APIRequest: true" \
-H "Accept: application/json" \
http://localhost:8080/ocs/v2.php/apps/pettycash/api/v1/context
9. Frontend Rules

Frontend location:

src/

Structure:

src/
 ├── App.vue
 ├── main.ts
 ├── services/
 ├── views/
 └── components/

Do not assume:

src/main.js

Project uses:

src/main.ts
10. Administration Page Rules

All configurable features must be controlled from:

Administration

Dashboard should not contain configuration.

Dashboard is for:

status
overview
quick information

Administration contains:

timezone
currency defaults
OCR settings
workflow options
feature enable/disable switches
11. Cost List Business Rules

Current requirements:

Multiple projects

A user must have separate Cost Lists per project.

Example:

User:

Ali

Projects:

Project A
Project B

Allowed:

Ali -> Open Cost List -> Project A
Ali -> Open Cost List -> Project B
Multiple open Cost Lists

Controlled by administrator setting.

Admin can enable/disable:

Allow multiple open Cost Lists
Delete open Cost List

A purchaser can delete:

OPEN

Cost Lists.

After submission:

M1_REVIEW
M2_REVIEW
FINAL

cannot be deleted.

Use soft delete.

12. Nextcloud Maintenance Mode

When upgrade fails:

Check:

php occ maintenance:mode

Do not repeatedly run:

occ upgrade

while a failed migration is recorded as incomplete.

Check:

data/nextcloud.log
13. Debugging Order

When an error appears:

Follow this order:

Read Nextcloud log
data/nextcloud.log
Check PHP error
docker logs docker-nextcloud-1
Check database structure

Example:

SHOW COLUMNS FROM oc_pcash_list;
Check migration status
SELECT *
FROM oc_migrations
WHERE app='pettycash';
14. Before Changing Code

Always:

Update requirement document
Create minor version plan
List affected files
Change backend first
Run migration
Test API
Update frontend
Test workflow
15. Version Management Rule

Every feature group is a minor version.

Example:

0.4.0

Current:

0.4.2

Next feature:

0.4.3

At the end of each minor version provide:

Changed files list
Database migration list
New API routes
Frontend changes
Testing instructions
16. Known Project Decisions
Purchasers

Current requirement:

Do not manually type usernames.

Future implementation:

Use Nextcloud user selector.

Admin

Everything configurable must be editable.

No hidden constants.

Dashboard

Must not contain:

timezone settings
OCR settings
currency configuration
workflow switches

Those belong to Administration.

17. Assistant Rules for This Project

When modifying PettyCash:

Check existing project patterns first.
Do not introduce new Nextcloud conventions.
Prefer existing migrations as examples.
Never manually add database prefixes.
Never assume generic PHP behavior over Nextcloud behavior.
Always provide complete files when requested.
Always provide migration instructions.
Always track changes by minor version.

End of document