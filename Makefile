.PHONY: help install build up down restart logs shell test lint stan security migrate fresh seed clean

.DEFAULT_GOAL := help

# Environment variables
PROFILE ?= dev
COMPOSE := docker-compose --profile $(PROFILE)

help: ## Show this help message
	@echo 'Usage: make [target] [PROFILE=dev|staging|prod]'
	@echo ''
	@echo 'Available targets:'
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z_-]+:.*?## / {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)

# Installation & Build
install: ## Install composer dependencies
	$(COMPOSE) run --rm app composer install

install-prod: ## Install production dependencies (no dev)
	$(COMPOSE) run --rm app composer install --no-dev --optimize-autoloader --no-interaction

build: ## Build Docker images
	$(COMPOSE) build --no-cache

build-dev: ## Build Docker images for development
	BUILD_TARGET=development $(COMPOSE) build

# Service Management
up: ## Start all services
	$(COMPOSE) up -d
	@echo "Waiting for services to be healthy..."
	@sleep 5
	@make health-check

down: ## Stop all services
	$(COMPOSE) down

restart: ## Restart all services
	@make down
	@make up

stop: ## Stop services without removing
	$(COMPOSE) stop

ps: ## List running services
	$(COMPOSE) ps

# Logs
logs: ## Tail logs from all services
	$(COMPOSE) logs -f

logs-app: ## Tail logs from app service
	$(COMPOSE) logs -f app

logs-postgres: ## Tail logs from postgres service
	$(COMPOSE) logs -f postgres

logs-redis: ## Tail logs from redis service
	$(COMPOSE) logs -f redis

# Shell Access
shell: ## Open shell in app container
	$(COMPOSE) exec app /bin/bash

shell-root: ## Open root shell in app container
	$(COMPOSE) exec -u root app /bin/bash

db-console: ## Open PostgreSQL console
	$(COMPOSE) exec postgres psql -U rekberkan -d rekberkan

redis-console: ## Open Redis console
	$(COMPOSE) exec redis redis-cli -a "$${REDIS_PASSWORD}"

# Testing
test: ## Run PHPUnit tests
	$(COMPOSE) exec app php artisan test

test-coverage: ## Run tests with coverage
	$(COMPOSE) exec app php artisan test --coverage --min=80

test-unit: ## Run unit tests only
	$(COMPOSE) exec app php artisan test --testsuite=Unit

test-feature: ## Run feature tests only
	$(COMPOSE) exec app php artisan test --testsuite=Feature

test-integration: ## Run integration tests only
	$(COMPOSE) exec app php artisan test --testsuite=Integration

# Code Quality
lint: ## Run Laravel Pint and fix issues
	$(COMPOSE) exec app ./vendor/bin/pint

lint-check: ## Check code style without fixing
	$(COMPOSE) exec app ./vendor/bin/pint --test

stan: ## Run PHPStan static analysis
	$(COMPOSE) exec app ./vendor/bin/phpstan analyse --memory-limit=2G

analyse: stan ## Alias for stan

security: ## Run security vulnerability check
	$(COMPOSE) exec app composer audit

ci: lint-check stan test security ## Run all CI checks

# Database Operations
migrate: ## Run database migrations
	$(COMPOSE) exec app php artisan migrate --force

migrate-rollback: ## Rollback last migration
	$(COMPOSE) exec app php artisan migrate:rollback

migrate-status: ## Show migration status
	$(COMPOSE) exec app php artisan migrate:status

migrate-fresh: ## Drop all tables and re-run migrations
	$(COMPOSE) exec app php artisan migrate:fresh --force

seed: ## Run database seeders
	$(COMPOSE) exec app php artisan db:seed --force

fresh: migrate-fresh seed ## Fresh database with seeding

# Application Commands
key-generate: ## Generate application key
	$(COMPOSE) exec app php artisan key:generate

cache-clear: ## Clear all caches
	$(COMPOSE) exec app php artisan cache:clear
	$(COMPOSE) exec app php artisan config:clear
	$(COMPOSE) exec app php artisan route:clear
	$(COMPOSE) exec app php artisan view:clear

optimize: ## Optimize application for production
	$(COMPOSE) exec app php artisan config:cache
	$(COMPOSE) exec app php artisan route:cache
	$(COMPOSE) exec app php artisan view:cache
	$(COMPOSE) exec app php artisan event:cache

octane-reload: ## Reload Octane server
	$(COMPOSE) exec app php artisan octane:reload

horizon-terminate: ## Terminate Horizon gracefully
	$(COMPOSE) exec app php artisan horizon:terminate

queue-work: ## Start queue worker
	$(COMPOSE) exec app php artisan queue:work

queue-restart: ## Restart queue workers
	$(COMPOSE) exec app php artisan queue:restart

queue-failed: ## List failed queue jobs
	$(COMPOSE) exec app php artisan queue:failed

# Health Checks
health-check: ## Check application health
	@curl -sf http://localhost:8000/health/live | jq . || echo "❌ Health check failed"

ready-check: ## Check application readiness
	@curl -sf http://localhost:8000/health/ready | jq . || echo "❌ Readiness check failed"

# Setup Workflows
dev-setup: ## Complete development setup
	@echo "🚀 Setting up development environment..."
	@make build-dev
	@make up
	@make install
	@make key-generate
	@make migrate
	@echo "✅ Development environment ready!"
	@echo "📝 API available at: http://localhost:8000"
	@echo "🔍 Health check: http://localhost:8000/health/live"

prod-setup: ## Production deployment setup
	@echo "🚀 Setting up production environment..."
	@make build PROFILE=prod
	@make up PROFILE=prod
	@make install-prod PROFILE=prod
	@make migrate PROFILE=prod
	@make optimize PROFILE=prod
	@echo "✅ Production environment ready!"
	@make health-check

# Cleanup
clean: ## Clean up containers, volumes, and caches
	@echo "🧹 Cleaning up..."
	$(COMPOSE) down -v
	docker system prune -f
	@echo "✅ Cleanup complete"

clean-logs: ## Clean application logs
	$(COMPOSE) exec app rm -rf storage/logs/*.log
	@echo "✅ Logs cleaned"

# Documentation
openapi-generate: ## Generate OpenAPI documentation
	$(COMPOSE) exec app php artisan l5-swagger:generate
	@echo "📚 OpenAPI spec generated at openapi/openapi.yaml"

# Utilities
artisan: ## Run artisan command (usage: make artisan CMD="migrate:status")
	$(COMPOSE) exec app php artisan $(CMD)

composer: ## Run composer command (usage: make composer CMD="require package")
	$(COMPOSE) exec app composer $(CMD)

# Info
info: ## Show environment information
	@echo "Environment: $(PROFILE)"
	@echo "Compose command: $(COMPOSE)"
	@$(COMPOSE) exec app php -v
	@$(COMPOSE) exec app php artisan --version
