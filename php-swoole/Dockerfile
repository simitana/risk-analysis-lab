FROM php:8.3-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        libssl-dev \
        libbrotli-dev \
        pkg-config \
        $PHPIZE_DEPS \
    && pecl install swoole \
    && docker-php-ext-enable swoole \
    && docker-php-ext-install sockets \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS pkg-config \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY src/ ./src/

ENV HOST=0.0.0.0 \
    PORT=9501

EXPOSE 9501

HEALTHCHECK --interval=5s --timeout=3s --retries=5 \
    CMD php -r "exit(@file_get_contents('http://127.0.0.1:9501/health') ? 0 : 1);"

CMD ["php", "src/server.php"]
