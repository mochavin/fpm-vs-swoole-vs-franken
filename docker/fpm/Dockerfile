FROM php:8.3-fpm-alpine

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
RUN echo "opcache.enable=1" >> /usr/local/etc/php/conf.d/opcache.ini && \
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

# Install dependencies (no dev for production-like benchmark)
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress

# Copy entrypoint
COPY docker/fpm/entrypoint.sh /usr/local/bin/entrypoint.sh
COPY docker/fpm/www.conf /usr/local/etc/php-fpm.d/www.conf
RUN chmod +x /usr/local/bin/entrypoint.sh

# Set permissions
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 9000

CMD ["/usr/local/bin/entrypoint.sh"]
