# Starts the db service first and waits until it is healthy (see docker-compose.yml).
PHP = docker compose run --rm php
# Without the database: unit tests, static analysis and mutation testing must not need it.
PHP_NO_DB = docker compose run --rm --no-deps php

.PHONY: setup test test-unit test-integration migrate stan trace mutation check web-setup web-test web-check

setup:            ## build the PHP image and install backend dependencies
	docker compose build php
	$(PHP_NO_DB) composer install

test:             ## all backend tests: unit + integration (starts MariaDB)
	$(PHP) vendor/bin/phpunit

test-unit:        ## unit tests only: no database, no I/O
	$(PHP_NO_DB) vendor/bin/phpunit --testsuite=Unit

test-integration: ## integration tests: real MariaDB (schema smoke_detector_test) + the Slim app in-process
	$(PHP) vendor/bin/phpunit --testsuite=Integration

migrate:          ## run the database migrations on the local dev schema (smoke_detector)
	$(PHP) php bin/console migrate

stan:             ## static analysis (PHPStan, max level)
	$(PHP_NO_DB) vendor/bin/phpstan analyse --no-progress --memory-limit=512M

trace:            ## requirement → test traceability report (and the tests of the tool itself)
	PYTHONDONTWRITEBYTECODE=1 python3 -m unittest tools/test_traceability.py
	python3 tools/traceability.py

# With more memory than PHP's default 128 MB: past 2,000 mutants Infection itself runs out of it while
# it cleans up after the reports are written, and the run fails although the score is fine.
mutation:         ## mutation testing: how many deliberately broken versions of the code do the unit tests catch?
	$(PHP_NO_DB) php -d memory_limit=512M vendor/bin/infection --threads=8 --no-progress --show-mutations=0

check: test stan trace mutation   ## everything: all tests, static analysis, traceability, mutation testing

web-setup:        ## install the web client's dependencies exactly as locked (npm ci in web/)
	cd web && npm ci

web-test:         ## web client tests (Vitest)
	cd web && npm test

web-check:        ## web client: type check, lint, API types up to date, tests
	cd web && npm run check
