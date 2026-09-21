FROM php:8.4-cli-alpine AS base

# kryoptic (PKCS#11 3.2 soft token, ADR-014) - built from source because Alpine
# has no package. Built on the runtime image itself so musl and libcrypto.so.3
# match exactly; `pqc` is what brings CKM_ML_DSA. Pinned to a release tag.
FROM base AS kryoptic
ARG KRYOPTIC_VERSION=v1.5.2
RUN apk add --no-cache rust cargo clang20-dev llvm20-dev sqlite-dev openssl-dev pkgconf musl-dev curl \
    && mkdir -p /build && cd /build \
    && curl -fsSL --retry 5 --retry-delay 5 "https://github.com/latchset/kryoptic/archive/refs/tags/${KRYOPTIC_VERSION}.tar.gz" \
        | tar xz --strip-components=1 \
    && CONFDIR=/etc cargo build --release --no-default-features --features standard,dynamic,pqc,profiles \
    && ls -la target/release/libkryoptic_pkcs11.so

FROM base

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
    sqlite-libs \
    opensc \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_pgsql intl opcache gd \
    && rm -rf /var/cache/apk/*

# kryoptic PKCS#11 token (ADR-005, ADR-014) - keys live in the token, never
# exported. pyHanko loads the module in-process; swap PKCS11_MODULE for a
# hardware HSM client library later with no code changes. The slot list and the
# per-token SQLite files live together on a mounted volume; bin/kryoptic_slots.py
# writes the config, nothing else does.
COPY --from=kryoptic /build/target/release/libkryoptic_pkcs11.so /usr/lib/libkryoptic_pkcs11.so
RUN mkdir -p /var/lib/kryoptic/tokens
ENV KRYOPTIC_CONF=/var/lib/kryoptic/token.conf \
    PKCS11_MODULE=/usr/lib/libkryoptic_pkcs11.so

# pyHanko (PAdES signing, ADR-007) and everything it pulls in, pinned to the
# version in requirements.txt - transitive packages included, so a rebuild
# cannot float `cryptography` or `python-pkcs11` under the drivers, and CI's
# pip-audit covers the exact set. tzdata is required: pyHanko resolves a
# ZoneInfo at import time.
COPY requirements.txt /tmp/requirements.txt
RUN apk add --no-cache python3 py3-pip tzdata \
    && pip3 install --break-system-packages --no-cache-dir --no-deps -r /tmp/requirements.txt \
    && rm -rf /var/cache/apk/* /tmp/requirements.txt

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
