FROM php:8.3-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
    pkg-config \
    zlib1g-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    libwebp-dev \
    libfreetype6-dev \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" gd

WORKDIR /app
COPY . /app

ENV PORT=8000
EXPOSE 8000

CMD ["sh", "-c", "php -S 0.0.0.0:${PORT} index.php"]
