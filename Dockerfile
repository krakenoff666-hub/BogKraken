FROM php:8.2-cli

WORKDIR /app

ENV PHP_CLI_SERVER_WORKERS=12

COPY . .

EXPOSE 8080

CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-8080} -t ."]