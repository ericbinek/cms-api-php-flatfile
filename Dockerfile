FROM php:8.5-alpine

WORKDIR /app

COPY . .

RUN apk add --no-cache icu-libs \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev \
    && docker-php-ext-install intl \
    && apk del .build-deps
RUN mkdir -p data && chown -R www-data:www-data /app

USER www-data

EXPOSE 3002

HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
  CMD wget --quiet --spider http://127.0.0.1:3002/health || exit 1

CMD ["php", "-S", "0.0.0.0:3002", "-t", "public", "src/server.php"]
