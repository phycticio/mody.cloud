ARG WORDPRESS_IMAGE_VERSION=${WORDPRESS_IMAGE_VERSION:-latest-fpm-alpine}

FROM wordpress:${WORDPRESS_IMAGE_VERSION}

ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

ARG USER_ID=1000
ARG GROUP_ID=1000
ARG INSTALL_XDEBUG=false

# Runtime packages kept in the final image.
RUN apk add --no-cache \
    freetype \
    imagemagick \
    libavif \
    libjpeg-turbo \
    libpng \
    libwebp \
    libzip \
    mariadb-client \
    msmtp \
    shadow \
    unzip \
    zip \
    && usermod -u "${USER_ID}" www-data \
    && groupmod -g "${GROUP_ID}" www-data

# Build PHP extensions with Alpine build dependencies, then remove build-only packages.
RUN apk add --no-cache --virtual .build-deps \
    autoconf \
    freetype-dev \
    g++ \
    libavif-dev \
    libjpeg-turbo-dev \
    libpng-dev \
    libwebp-dev \
    libzip-dev \
    linux-headers \
    make \
    pcre-dev \
    $PHPIZE_DEPS \
    && docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg \
        --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        exif \
        gd \
        mysqli \
        pdo_mysql \
        zip \
    && install-php-extensions redis \
    && if [ "${INSTALL_XDEBUG}" = "true" ]; then \
        install-php-extensions xdebug; \
    fi \
    && apk del .build-deps

# WP-CLI. Keep if this image is also used for operational WP-CLI tasks.
RUN curl -fsSL -o /usr/local/bin/wp \
    https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
    && chmod +x /usr/local/bin/wp

# Composer. Optional in stricter production images if dependencies are built in CI.
RUN curl -fsSL https://getcomposer.org/installer | \
    php -- --install-dir=/usr/local/bin --filename=composer

COPY ./php/*.ini /usr/local/etc/php/conf.d/

RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 775 /var/www/html