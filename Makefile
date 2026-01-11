.PHONY: help install build up down restart logs shell test lint analyse security migrate fresh seed

.DEFAULT_GOAL := help

help: ## Show this help message
	@echo 'Usage: make [target]'
	@echo ''
	@echo 'Available targets:'
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z_-]+:.*?## / {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)

install: ## Install composer dependencies
	docker-compose run --rm app composer install

install-prod: ## Install production dependencies (no dev)
	docker-compose run --rm app composer install --no-dev --optimize-autoloader

build: ## Build Docker images
	docker-compose build --no-cache

up: ## Start all services (development profile)
	docker-compose --profile development up -d

up-prod: ## Start all services (production profile)
	docker-compose --profile production up -d

down: ## Stop all services
	docker-compose down

restart: down up ## Restart all services

logs: ## Tail logs from all services
	docker-compose logs -f

logs-app: ## Tail logs from app service
	docker-compose logs -f app

shell: ## Open shell in app container
	docker-compose exec app /bin/bash

shell-root: ## Open root shell in app container
	docker-compose exec -u root app /bin/bash

test: ## Run PHPUnit tests
	docker-compose exec app php artisan test

test-coverage: ## Run tests with coverage
	docker-compose exec app php artisan test --coverage

lint: ## Run Laravel Pint linter
	docker-compose exec app ./vendor/bin/pint

lint-check: ## Check code style without fixing
	docker-compose exec app ./vendor/bin/pint --test

analyse: ## Run PHPStan static analysis
	docker-compose exec app ./vendor/bin/phpstan analyse

security: ## Run security vulnerability check
	docker-compose exec app composer audit

migrate: ## Run database migrations
	docker-compose exec app php artisan migrate

migrate-fresh: ## Drop all tables and re-run migrations
	docker-compose exec app php artisan migrate:fresh

fresh: migrate-fresh seed ## Fresh database with seeding

seed: ## Run database seeders
	docker-compose exec app php artisan db:seed

key-generate: ## Generate application key
	docker-compose exec app php artisan key:generate

jwt-secret: ## Generate JWT secret
	docker-compose exec app php artisan jwt:secret

cache-clear: ## Clear all caches
	docker-compose exec app php artisan cache:clear
	docker-compose exec app php artisan config:clear
	docker-compose exec app php artisan route:clear
	docker-compose exec app php artisan view:clear

optimize: ## Optimize application
	docker-compose exec app php artisan config:cache
	docker-compose exec app php artisan route:cache
	docker-compose exec app php artisan view:cache
	docker-compose exec app php artisan event:cache

octane-reload: ## Reload Octane server
	docker-compose exec app php artisan octane:reload

horizon-terminate: ## Terminate Horizon
	docker-compose exec app php artisan horizon:terminate

queue-work: ## Start queue worker
	docker-compose exec app php artisan queue:work

queue-restart: ## Restart queue workers
	docker-compose exec app php artisan queue:restart

ci-check: lint-check analyse test security ## Run all CI checks

db-console: ## Open PostgreSQL console
	docker-compose exec postgres psql -U rekberkan -d rekberkan

redis-console: ## Open Redis console
	docker-compose exec redis redis-cli

dev-setup: install key-generate jwt-secret migrate seed ## Complete development setup

prod-deploy: install-prod optimize migrate ## Production deployment steps

health-check: ## Check application health
	@curl -f http://localhost:8000/health || echo "Health check failed"

ready-check: ## Check application readiness
	@curl -f http://localhost:8000/ready || echo "Readiness check failed"
