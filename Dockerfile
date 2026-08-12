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

# pyHanko (PAdES signing, ADR-007). tzdata is required — pyHanko resolves a
# ZoneInfo at import time. image-support extra = Pillow, for visible stamps.
RUN apk add --no-cache python3 py3-pip tzdata \
    && pip3 install --break-system-packages --no-cache-dir \
        "pyhanko[pkcs11,image-support,qr,opentype]" pyhanko-cli \
    && rm -rf /var/cache/apk/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Raise PHP upload limits above the app's 10 MB document cap (placed late so the
# heavy apk/pip layers above stay cached on rebuild).
COPY docker/php-uploads.ini /usr/local/etc/php/conf.d/zz-sigil-uploads.ini

# Tailwind standalone binary, baked in rather than fetched per build: var/ is an
# anonymous volume, so the bundle's own download would repeat on every CI run and
# die whenever GitHub returns a 503. TAILWIND_BINARY makes the bundle skip the
# download entirely - keep this version in lockstep with binary_version in
# config/packages/symfonycasts_tailwind.yaml, which it overrides.
ARG TAILWIND_VERSION=v4.1.11
RUN case "$(uname -m)" in \
        x86_64) TW_ARCH=linux-x64-musl ;; \
        aarch64) TW_ARCH=linux-arm64-musl ;; \
        *) echo "unsupported architecture: $(uname -m)" >&2; exit 1 ;; \
    esac \
    && curl -fsSL --retry 5 --retry-delay 5 --retry-all-errors \
        -o /usr/local/bin/tailwindcss \
        "https://github.com/tailwindlabs/tailwindcss/releases/download/${TAILWIND_VERSION}/tailwindcss-${TW_ARCH}" \
    && chmod +x /usr/local/bin/tailwindcss
ENV TAILWIND_BINARY=/usr/local/bin/tailwindcss

COPY docker-entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
