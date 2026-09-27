PettyCash V1 - Development Environment Context

تاریخ وضعیت: شروع توسعه نسخه ۱

1- محیط Host

کاربر سیستم: ubuntu

ریشه پروژه ها:

/home/ubuntu/projects

ساختار:

/home/ubuntu/projects/

├── pettycash │ └── PettyCash V1 در حال توسعه

├── pettycash_legacy_backup │ └── نسخه قبلی PettyCash 2.0.1

├── pts-nextcloud │ └── اپلیکیشن PTS

└── nextcloud-lab └── محیط توسعه مشترک Nextcloud (Docker)

نکته: محیط Docker دیگر داخل هیچ مخزن اپلیکیشنی نیست و توسط هر دو پروژه
PTS و PettyCash به صورت مشترک استفاده می شود.

2- محیط Docker Nextcloud

مسیر Docker Compose:

/home/ubuntu/projects/nextcloud-lab

فایل های اصلی:

compose.yaml .env

سرویس ها:

db nextcloud-volume-init nextcloud

Container ها:

Nextcloud: docker-nextcloud-1

Image: nextcloud:34-apache

Port: 8080 -> 80

MariaDB:

Container: docker-db-1

Image: mariadb:11.4

3- دستورات مهم Nextcloud

اجرای occ:

docker exec --user www-data docker-nextcloud-1 php occ

لیست App ها:

docker exec --user www-data docker-nextcloud-1 php occ app:list

بررسی PettyCash:

docker exec --user www-data docker-nextcloud-1 php occ app:list | grep
petty

Restart Nextcloud:

cd /home/ubuntu/projects/nextcloud-lab

docker compose restart nextcloud

4- دیتابیس MariaDB

Database:

nextcloud

Credentials:

Root User: root

Root Password: root

Application User: nextcloud

Application Password: nextcloud

ورود:

docker exec -it docker-db-1 mariadb -u root -p

5- Docker Volumes

Volume ها:

docker_pts-nextcloud

docker_pts-db

نام پروژه Compose:

docker

این نام در compose.yaml به صورت صریح (name: docker) تثبیت شده است و پیشوند
تمام Container، Network و Volume هاست. تغییر آن باعث می شود Compose Volume
هایی با نام دیگری بجوید، پیدا نکند، و Docker به صورت خودکار Volume خالی
بسازد؛ یعنی یک Nextcloud کاملاً جدید با دیتابیس خالی در حالی که داده واقعی
کنار گذاشته شده است.

هشدار:

این Volume ها نباید حذف شوند.

شامل:

-   کاربران Nextcloud
-   فایل ها
-   تنظیمات
-   App های دیگر

6- Mount های Nextcloud

Nextcloud:

docker_pts-nextcloud

به:

/var/www/html

PettyCash:

Host:

/home/ubuntu/projects/pettycash

Container:

/var/www/html/custom_apps/pettycash

PTS App:

Host:

/home/ubuntu/projects/pts-nextcloud

Container:

/var/www/html/custom_apps/pts

نکته: مسیر Host هر دو اپ، ریشه مخزن آن هاست و با bind mount به
custom_apps متصل می شود.

7- وضعیت PettyCash V1

App ID:

pettycash

Version:

1.0.0

Namespace:

OCA

Status:

Enabled

مسیر کد:

/home/ubuntu/projects/pettycash

8- ساختار فعلی App

pettycash/

├── appinfo ├── lib ├── css ├── js ├── src ├── templates ├──
composer.json ├── package.json └── README.md

9- نسخه Legacy

مسیر:

/home/ubuntu/projects/pettycash_legacy_backup

نسخه:

2.0.1

کاربرد:

-   بررسی Migration
-   استخراج تجربه
-   مقایسه

این نسخه مبنای توسعه V1 نیست.

10- وضعیت دیتابیس PettyCash قدیمی

جداول قبلی:

oc_pcash_action oc_pcash_attach oc_pcash_audit oc_pcash_category
oc_pcash_currency oc_pcash_decision oc_pcash_destination_owner
oc_pcash_list oc_pcash_manager_assignment oc_pcash_member oc_pcash_ocr
oc_pcash_project oc_pcash_revision oc_pcash_txn oc_pcash_vehicle

وضعیت داده:

transaction = 0

audit = 1

list = 1


12- اصول محیط

-   Docker مشترک است و نباید حذف شود.
-   Volume های Nextcloud حذف نمی شوند.
-   پروژه های دیگر Docker بدون تغییر باقی می مانند.
-   نسخه قبلی فقط Backup است.
-   طراحی نسخه ۱ فقط بر اساس اسناد تایید شده انجام می شود.

پایان Dev Environment Context
