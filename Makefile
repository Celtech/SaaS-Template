.PHONY: up down restart build shell logs worker migrate migration seed db-test test test-unit test-integration test-functional test-coverage audit cs cs-fix stan secret help

DOCKER_COMPOSE = docker compose
PHP = $(DOCKER_COMPOSE) exec app php
COMPOSER = $(DOCKER_COMPOSE) exec app composer
CONSOLE = $(PHP) bin/console

# ──────────────────────────────────────────────
#  Docker
# ──────────────────────────────────────────────

install: ## Install Composer dependencies (run once after first up, or after composer.json changes)
	$(COMPOSER) install

hooks: ## Install git hooks (run once per clone)
	cp docker/git-hooks/pre-commit .git/hooks/pre-commit
	cp docker/git-hooks/pre-push .git/hooks/pre-push
	chmod +x .git/hooks/pre-commit .git/hooks/pre-push
	@echo "Git hooks installed."

up: ## Start all containers in the background
	$(DOCKER_COMPOSE) up --detach

down: ## Stop and remove all containers
	$(DOCKER_COMPOSE) down --remove-orphans

restart: ## Restart the app container
	$(DOCKER_COMPOSE) restart app

build: ## Rebuild all Docker images
	$(DOCKER_COMPOSE) build --no-cache

shell: ## Open an interactive shell in the app container
	$(DOCKER_COMPOSE) exec app bash

logs: ## Tail logs from all containers
	$(DOCKER_COMPOSE) logs --follow

# ──────────────────────────────────────────────
#  Application
# ──────────────────────────────────────────────

worker: ## Start Messenger consumer and Scheduler worker (foreground)
	$(CONSOLE) messenger:consume async scheduler_default --time-limit=3600 -vv

migrate: ## Run pending database migrations
	$(CONSOLE) doctrine:migrations:migrate --no-interaction

migration: ## Generate a new migration from entity changes
	$(CONSOLE) doctrine:migrations:diff

seed: ## Seed the database with default roles, plans, and first Super Admin
	$(CONSOLE) app:seed

cache-clear: ## Clear the Symfony cache
	$(CONSOLE) cache:clear

# ──────────────────────────────────────────────
#  Frontend
# ──────────────────────────────────────────────

tailwind: ## Build Tailwind CSS (one-shot)
	$(CONSOLE) tailwind:build

tailwind-watch: ## Build Tailwind CSS in watch mode
	$(CONSOLE) tailwind:build --watch

# ──────────────────────────────────────────────
#  Testing
# ──────────────────────────────────────────────

db-test: ## Create and migrate the test database (run once after first setup)
	$(DOCKER_COMPOSE) exec -e APP_ENV=test app php bin/console doctrine:database:create --if-not-exists
	$(DOCKER_COMPOSE) exec -e APP_ENV=test app php bin/console doctrine:migrations:migrate --no-interaction

test: audit cs stan ## Run full test suite (audit, cs, stan, phpunit)
	$(PHP) bin/phpunit

test-unit: ## Run unit tests only
	$(PHP) bin/phpunit --testsuite=unit

test-integration: ## Run integration tests only
	$(PHP) bin/phpunit --testsuite=integration

test-functional: ## Run functional tests only
	$(PHP) bin/phpunit --testsuite=functional

test-coverage: ## Run tests with HTML coverage report
	$(PHP) bin/phpunit --coverage-html coverage

# ──────────────────────────────────────────────
#  Code quality
# ──────────────────────────────────────────────

audit: ## Run Composer security audit
	$(COMPOSER) audit

cs: ## Check code style (PHP-CS-Fixer, dry run)
	$(PHP) vendor/bin/php-cs-fixer fix --dry-run --diff

cs-fix: ## Fix code style issues automatically
	$(PHP) vendor/bin/php-cs-fixer fix

stan: ## Run PHPStan static analysis
	$(PHP) vendor/bin/phpstan analyse --memory-limit=512M

# ──────────────────────────────────────────────
#  Utilities
# ──────────────────────────────────────────────

secret: ## Generate a new APP_SECRET value
	$(PHP) -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'

.DEFAULT_GOAL := help
