# All tooling runs in Docker; the host needs neither PHP nor Composer.
# PHP_VERSION=8.2 make all   -> run everything on another PHP version.
PHP_VERSION ?= 8.5
IMAGE := printdock-dev:$(PHP_VERSION)
DOCKER_USER := $(shell id -u):$(shell id -g)
RUN := docker run --rm --user $(DOCKER_USER) -e HOME=/tmp -e COMPOSER_HOME=/tmp/composer \
	-e PHP_CS_FIXER_IGNORE_ENV=1 -v $(CURDIR):/app -w /app $(IMAGE)

.PHONY: image install test stan cs cs-fix conformance test-mysql all clean

image:
	docker build -q --build-arg PHP_VERSION=$(PHP_VERSION) -t $(IMAGE) docker >/dev/null

install: image
	$(RUN) composer install --no-interaction --prefer-dist --no-progress

test: image
	$(RUN) vendor/bin/phpunit

stan: image
	$(RUN) vendor/bin/phpstan analyse --memory-limit=1G --no-progress

cs: image
	$(RUN) vendor/bin/php-cs-fixer fix --dry-run --diff

cs-fix: image
	$(RUN) vendor/bin/php-cs-fixer fix

conformance: image
	$(RUN) sh tests/conformance/run.sh

# PDO adapter contract tests against a throw-away MySQL 8 container (own network, removed afterwards).
test-mysql: image
	sh tests/run-mysql.sh $(IMAGE) $(DOCKER_USER)

all: install test stan cs conformance

clean:
	rm -rf vendor var .phpunit.cache .php-cs-fixer.cache
