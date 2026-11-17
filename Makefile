PHP = docker compose run --rm php

.PHONY: setup test stan trace mutation check

setup:            ## build the PHP image and install backend dependencies
	docker compose build php
	$(PHP) composer install

test:             ## backend unit tests
	$(PHP) vendor/bin/phpunit

stan:             ## static analysis (PHPStan, max level)
	$(PHP) vendor/bin/phpstan analyse --no-progress

trace:            ## requirement → test traceability report
	python3 tools/traceability.py

mutation:         ## mutation testing: how many deliberately broken versions of the code do the tests catch?
	$(PHP) vendor/bin/infection --threads=8 --no-progress --show-mutations=0

check: test stan trace   ## everything CI runs
