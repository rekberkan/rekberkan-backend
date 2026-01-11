# syntax=docker/dockerfile:1.4
# Multi-stage build for production-grade PHP 8.3 + Laravel 11 + Octane/Swoole

# Base stage with common dependencies
FROM php:8.3-cli-alpine AS base

WORKDIR /var/www/html

# Install system dependencies
RUN apk add --no-cache \
    postgresql-client \
    libzip \
    bash \
    curl \
    git \
    unzip

# Install PHP extensions build dependencies
RUN apk add --no-cache --virtual .build-deps \
    $PHPIZE_DEPS \
    postgresql-dev \
    libzip-dev \
    linux-headers

# Install PHP extensions
RUN docker-php-ext-install -j$(nproc) \
    pdo_pgsql \
    pgsql \
    pcntl \
    zip \
    bcmath

# Install Swoole and Redis extensions
RUN pecl install swoole-5.1.2 redis-6.0.2 \
    && docker-php-ext-enable swoole redis

# Clean up build dependencies
RUN apk del .build-deps

# Install Composer
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

# Development stage
FROM base AS development

ENV APP_ENV=local
ENV APP_DEBUG=true

# Install development tools
RUN apk add --no-cache nodejs npm

# Copy composer files
COPY composer.json composer.lock ./

# Install all dependencies (including dev)
RUN composer install \
    --no-interaction \
    --no-progress \
    --prefer-dist

# Copy application
COPY . .

# Set permissions
RUN mkdir -p storage/logs storage/framework/{cache,sessions,views} bootstrap/cache \
    && chmod -R 777 storage bootstrap/cache

EXPOSE 8000 6001

CMD ["php", "artisan", "octane:start", "--server=swoole", "--host=0.0.0.0", "--port=8000"]

# Production builder stage
FROM base AS builder

WORKDIR /var/www/html

# Copy composer files
COPY composer.json composer.lock ./

# Install production dependencies only
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --prefer-dist \
    --optimize-autoloader

# Production stage
FROM base AS production

LABEL maintainer="Rekberkan Engineering"
LABEL description="Rekberkan Backend - Core Banking Grade Escrow Platform"

ENV APP_ENV=production
ENV APP_DEBUG=false

# Install runtime dependencies
RUN apk add --no-cache \
    supervisor \
    nginx

# Copy PHP configuration
COPY --link docker/php/php.ini /usr/local/etc/php/php.ini
COPY --link docker/php/octane.ini /usr/local/etc/php/conf.d/octane.ini

# Copy Nginx configuration
COPY --link docker/nginx/nginx.conf /etc/nginx/nginx.conf
COPY --link docker/nginx/default.conf /etc/nginx/http.d/default.conf

# Copy Supervisor configuration
COPY --link docker/supervisor/supervisord.conf /etc/supervisor/supervisord.conf
COPY --link docker/supervisor/conf.d/ /etc/supervisor/conf.d/

# Create non-root user
RUN addgroup -g 1000 -S www \
    && adduser -u 1000 -S www -G www \
    && chown -R www:www /var/log/nginx /var/lib/nginx /run/nginx

# Copy application from builder
COPY --from=builder --chown=www:www /var/www/html/vendor ./vendor
COPY --chown=www:www . .

# Create and set permissions for storage directories
RUN mkdir -p \
    storage/logs \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    bootstrap/cache \
    && chown -R www:www storage bootstrap/cache \
    && chmod -R 755 storage bootstrap/cache

# Expose ports
EXPOSE 8000 6001

# Health check
HEALTHCHECK --interval=30s --timeout=3s --start-period=40s --retries=3 \
    CMD curl -f http://localhost:8000/health/live || exit 1

# Switch to non-root user
USER www

# Default command (run supervisor as www user)
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/supervisord.conf", "-n"]
