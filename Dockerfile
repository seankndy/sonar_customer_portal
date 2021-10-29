FROM phusion/baseimage:0.11 as base

ENV LC_ALL C.UTF-8

RUN add-apt-repository ppa:ondrej/php \
 && add-apt-repository ppa:ondrej/nginx-mainline \
 && install_clean \
      gettext \
      nginx \
      php7.4-fpm \
      php7.4-bcmath \
      php7.4-curl \
      php7.4-gmp \
      php7.4-mbstring \
      php7.4-sqlite3 \
      php7.4-zip \
      php7.4-dom \
      unzip

WORKDIR /var/www/html

COPY --chown=www-data --from=composer:1.8.4 /usr/bin/composer /tmp/composer
COPY composer.json composer.lock ./
RUN mkdir -p vendor \
 && chown www-data:www-data vendor \
 && COMPOSER_CACHE_DIR=/dev/null setuser www-data /tmp/composer install --no-dev --no-interaction --no-scripts --no-autoloader

COPY --chown=www-data . .

RUN COMPOSER_CACHE_DIR=/dev/null setuser www-data /tmp/composer install --no-dev --no-interaction --no-scripts --classmap-authoritative \
 && rm -rf /tmp/composer

COPY deploy/conf/nginx/sonar-customerportal.template /etc/nginx/conf.d/customerportal.template

COPY deploy/conf/php-fpm/ /etc/php/7.4/fpm/

COPY deploy/conf/cron.d/* /etc/cron.d/
RUN chmod -R go-w /etc/cron.d

RUN mkdir -p /etc/my_init.d
COPY deploy/*.sh /etc/my_init.d/

RUN mkdir /etc/service/php-fpm
COPY deploy/services/php-fpm.sh /etc/service/php-fpm/run

RUN mkdir /etc/service/nginx
COPY deploy/services/nginx.sh /etc/service/nginx/run

VOLUME /var/www/html/storage
EXPOSE 80 443
