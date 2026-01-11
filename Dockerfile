# Multi-stage build for production-grade PHP 8.3 + Laravel 11 + Octane/Swoole
# Build stage
FROM php:8.3-cli-alpine AS builder

WORKDIR /var/www/html

# Install build dependencies
RUN apk add --no-cache \
    git \
    unzip \
    libzip-dev \
    postgresql-dev \
    $PHPIZE_DEPS \
    linux-headers

# Install PHP extensions
RUN docker-php-ext-install -j$(nproc) \
    pdo_pgsql \
    pgsql \
    pcntl \
    zip \
    bcmath

# Install Swoole
RUN pecl install swoole-5.1.2 \
    && docker-php-ext-enable swoole

# Install Redis extension
RUN pecl install redis-6.0.2 \
    && docker-php-ext-enable redis

# Install Composer
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

# Copy composer files
COPY composer.json composer.lock ./

# Install dependencies (no dev)
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --prefer-dist \
    --optimize-autoloader

# Production stage
FROM php:8.3-cli-alpine

LABEL maintainer="Rekberkan Engineering"
LABEL description="Rekberkan Backend - Core Banking Grade Escrow Platform"

WORKDIR /var/www/html

# Install runtime dependencies
RUN apk add --no-cache \
    postgresql-client \
    libzip \
    supervisor \
    nginx \
    bash \
    curl

# Install PHP extensions (runtime)
RUN apk add --no-cache \
    $PHPIZE_DEPS \
    postgresql-dev \
    libzip-dev \
    linux-headers \
    && docker-php-ext-install -j$(nproc) \
        pdo_pgsql \
        pgsql \
        pcntl \
        zip \
        bcmath \
    && pecl install swoole-5.1.2 redis-6.0.2 \
    && docker-php-ext-enable swoole redis \
    && apk del $PHPIZE_DEPS

# Copy PHP configuration
COPY docker/php/php.ini /usr/local/etc/php/php.ini
COPY docker/php/octane.ini /usr/local/etc/php/conf.d/octane.ini

# Copy Nginx configuration
COPY docker/nginx/nginx.conf /etc/nginx/nginx.conf
COPY docker/nginx/default.conf /etc/nginx/http.d/default.conf

# Copy Supervisor configuration
COPY docker/supervisor/supervisord.conf /etc/supervisor/supervisord.conf
COPY docker/supervisor/conf.d/ /etc/supervisor/conf.d/

# Create non-root user
RUN addgroup -g 1000 -S www \
    && adduser -u 1000 -S www -G www

# Copy application from builder
COPY --from=builder --chown=www:www /var/www/html/vendor ./vendor
COPY --chown=www:www . .

# Set permissions
RUN chown -R www:www /var/www/html \
    && chmod -R 755 /var/www/html/storage \
    && chmod -R 755 /var/www/html/bootstrap/cache

# Create necessary directories
RUN mkdir -p storage/logs storage/framework/cache storage/framework/sessions storage/framework/views \
    && chown -R www:www storage bootstrap/cache

# Expose ports
EXPOSE 8000 6001

# Switch to non-root user
USER www

# Health check
HEALTHCHECK --interval=30s --timeout=3s --start-period=40s --retries=3 \
    CMD curl -f http://localhost:8000/health || exit 1

# Default command
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/supervisord.conf"]
