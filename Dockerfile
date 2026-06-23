FROM php:8.4-cli-alpine

RUN apk add --no-cache \
    bash \
    git \
    unzip \
    curl \
    libpq-dev \
    icu-dev \
    icu-libs \
    freetype-dev \
    libjpeg-turbo-dev \
    libpng-dev \
    softhsm \
    opensc \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_pgsql intl opcache gd \
    && rm -rf /var/cache/apk/*

# SoftHSM2 (PKCS#11 token) — keys live in the token, never exported (ADR-005).
# pyHanko loads the module in-process; swap PKCS11_MODULE for a hardware HSM
# client library later with no code changes. Token store is a mounted volume.
COPY docker/softhsm2.conf /etc/softhsm2.conf
RUN mkdir -p /var/lib/softhsm/tokens
ENV SOFTHSM2_CONF=/etc/softhsm2.conf \
    PKCS11_MODULE=/usr/lib/softhsm/libsofthsm2.so

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY docker-entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
