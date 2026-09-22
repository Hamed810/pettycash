.PHONY: install build check test php-check frontend-check preflight

install:
	composer install
	npm ci

build:
	npm run build

php-check:
	composer lint
	composer cs:check
	composer psalm
	composer test

frontend-check:
	npm run check

test: php-check frontend-check

check: test

preflight:
	./scripts/preflight.sh
