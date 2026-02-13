FROM dunglas/frankenphp:1-php8.3

# Install system dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpq-dev \
    libicu-dev \
    libonig-dev \
    zip \
    unzip \
    git \
    curl \
    && rm -rf /var/lib/apt/lists/*

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
RUN echo "opcache.enable=1" >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.enable_cli=1" >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.memory_consumption=256" >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.interned_strings_buffer=16" >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.max_accelerated_files=20000" >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.validate_timestamps=0" >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.jit=1255" >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.jit_buffer_size=128M" >> /usr/local/etc/php/conf.d/opcache.ini

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Copy application
COPY . .

# Install dependencies + Octane
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress && \
    composer require laravel/octane --no-interaction --no-progress

# Install Octane with FrankenPHP (skip downloading binary since we use the image's binary)
RUN php artisan octane:install --server=frankenphp --no-interaction || true

# Set SERVER_NAME to listen on :8000 with HTTP (no HTTPS for benchmark)
ENV SERVER_NAME=":8000"

# Copy entrypoint
COPY docker/franken/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Set permissions
RUN chmod -R 777 /app/storage /app/bootstrap/cache

EXPOSE 8000

CMD ["/usr/local/bin/entrypoint.sh"]
