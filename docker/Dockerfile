FROM php:8.3-cli
RUN apt-get update && apt-get install -y --no-install-recommends git unzip libzip-dev libpq-dev libsqlite3-dev \
 && docker-php-ext-install zip pdo_pgsql pdo_sqlite pcntl \
 && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN echo "memory_limit=-1" > /usr/local/etc/php/conf.d/memory.ini
WORKDIR /app
