FROM php:8.3-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install mysqli pdo pdo_mysql pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY . /app

RUN cp config.example.php config.php \
    && mkdir -p storage uploads uploads/licenses uploads/profiles \
    && chmod -R 777 storage uploads

CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-8080} -t /app"]
