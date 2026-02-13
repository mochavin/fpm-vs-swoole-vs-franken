FROM phpswoole/swoole:php8.3-alpine

# Install system dependencies
RUN apk add --no-cache \
    libpq-dev \
    icu-dev \
    linux-headers \
    oniguruma-dev \
    zip \
    unzip \
    git \
    curl

# Install PHP extensions
RUN docker-php-ext-install \
    pdo_pgsql \
    pgsql \
    pcntl \
    opcache \
    mbstring \
    intl \
    bcmath

# OPcache config for production
RUN echo "opcache.enable_cli=1" >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.memory_consumption=256" >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.interned_strings_buffer=16" >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.max_accelerated_files=20000" >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.validate_timestamps=0" >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.jit=1255" >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.jit_buffer_size=128M" >> /usr/local/etc/php/conf.d/opcache.ini

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy application
COPY . .

# Install dependencies + Octane
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress && \
    composer require laravel/octane --no-interaction --no-progress

# Install Octane with Swoole
RUN php artisan octane:install --server=swoole --no-interaction

# Copy entrypoint
COPY docker/swoole/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Set permissions
RUN chown -R root:root /var/www/html/storage /var/www/html/bootstrap/cache && \
    chmod -R 777 /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 8000

CMD ["/usr/local/bin/entrypoint.sh"]
