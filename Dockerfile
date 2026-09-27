# Test image: installs the SDK and runs its tests (live tests need TRUEUP_API_KEY).
FROM composer:2 AS vendor
WORKDIR /sdk
COPY composer.json ./
RUN composer install --no-interaction --no-progress --ignore-platform-reqs

FROM php:8.3-cli
WORKDIR /sdk
COPY --from=vendor /sdk/vendor ./vendor
COPY . .
COPY --from=vendor /sdk/vendor ./vendor
CMD ["vendor/bin/phpunit", "--testdox", "tests"]
