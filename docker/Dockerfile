# CVE-2026-109618 / CVE-2026-109619: pin the patched PHP 8.4 security release.
FROM php:8.4.27-fpm

RUN php -r 'if (PHP_VERSION !== "8.4.27") { fwrite(STDERR, "Expected PHP 8.4.27, got " . PHP_VERSION . PHP_EOL); exit(1); }'

ENV DEBIAN_FRONTEND=noninteractive
ENV TZ=Asia/Shanghai
ENV NGINX_VERSION=1.31.9

RUN apt-get update && apt-get install -y \
    wget \
    gnupg \
    software-properties-common \
    curl \
    ca-certificates \
    zip \
    unzip \
    git \
    cron \
    supervisor \
    sqlite3 \
    netcat-openbsd \
    procps \
    htop \
    build-essential \
    pkg-config \
    autoconf \
    automake \
    libtool \
    imagemagick \
    libmagickwand-dev \
    libmagickcore-dev \
    libvips-dev \
    libvips-tools \
    libvips42 \
    libpng-dev \
    libjpeg-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libwebp-dev \
    libavif-dev \
    libtiff-dev \
    libgif-dev \
    libheif-dev \
    librsvg2-dev \
    libraw-dev \
    libzip-dev \
    zlib1g-dev \
    libonig-dev \
    libxml2-dev \
    libicu-dev \
    libxslt1-dev \
    libffi-dev \
    libsqlite3-dev \
    fonts-dejavu-core \
    fontconfig \
    && rm -rf /var/lib/apt/lists/*

# Use the official mainline package containing the HTTP/2 security fixes.
RUN set -eux; \
    curl -fsSL https://nginx.org/keys/nginx_signing.key -o /tmp/nginx_signing.key; \
    mkdir -m 700 /tmp/nginx-gnupg; \
    gpg --homedir /tmp/nginx-gnupg --batch --import /tmp/nginx_signing.key; \
    gpg --homedir /tmp/nginx-gnupg --batch --export \
        573BFD6B3D8FBC641079A6ABABF5BD827BD9BF62 \
        8540A6F18833A80E9C1653A42FD21310B49F6B46 \
        9E9BE90EACBCDE69FE9B204CBCDCD8A38D88A2B3 \
        > /usr/share/keyrings/nginx-archive-keyring.gpg; \
    test -s /usr/share/keyrings/nginx-archive-keyring.gpg; \
    . /etc/os-release; \
    printf 'deb [signed-by=/usr/share/keyrings/nginx-archive-keyring.gpg] https://nginx.org/packages/mainline/debian %s nginx\n' "$VERSION_CODENAME" \
        > /etc/apt/sources.list.d/nginx.list; \
    apt-get update; \
    apt-get install -y --no-install-recommends "nginx=${NGINX_VERSION}-1~${VERSION_CODENAME}"; \
    sed -i 's/^user[[:space:]]\+nginx;/user www-data;/' /etc/nginx/nginx.conf; \
    grep -qx 'user www-data;' /etc/nginx/nginx.conf; \
    nginx -v 2>&1 | grep -Fx "nginx version: nginx/${NGINX_VERSION}"; \
    rm -rf /var/lib/apt/lists/* /tmp/nginx-gnupg /tmp/nginx_signing.key

RUN docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg \
        --with-webp \
    && docker-php-ext-configure intl \
    && docker-php-ext-install \
        pdo_mysql \
        pdo_sqlite \
        mysqli \
        gd \
        zip \
        bcmath \
        opcache \
        exif \
        intl \
        xsl \
        pcntl \
        ftp \
        ffi

RUN pecl install imagick redis vips \
    && docker-php-ext-enable imagick redis vips

COPY docker/config/php.ini /usr/local/etc/php/conf.d/custom.ini
COPY docker/config/www.conf /usr/local/etc/php-fpm.d/www.conf
COPY docker/config/nginx.conf /etc/nginx/conf.d/default.conf
COPY docker/config/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# 删除默认的 Docker 配置文件，避免配置冲突
RUN rm -f /usr/local/etc/php-fpm.d/zz-docker.conf

WORKDIR /var/www/html

COPY . .

RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html/storage \
    && chmod -R 755 /var/www/html/bootstrap/cache

COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

RUN mkdir -p /var/log/supervisor \
    && mkdir -p /var/www/html/database \
    && chown -R www-data:www-data /var/www/html/database

EXPOSE 80

# 添加健康检查
HEALTHCHECK --interval=30s --timeout=10s --start-period=60s --retries=3 \
    CMD curl -f http://localhost:80/ || exit 1

ENTRYPOINT ["/entrypoint.sh"]
