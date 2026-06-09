FROM php:8.2-fpm

RUN apt-get update && apt-get install -y \
    nginx libssl-dev pkg-config unzip zip libzip-dev \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install zip
RUN pecl install mongodb-1.19.0 && docker-php-ext-enable mongodb

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

COPY . /var/www/html/
WORKDIR /var/www/html
RUN composer install --no-interaction --optimize-autoloader --ignore-platform-req=ext-mongodb
RUN chown -R www-data:www-data /var/www/html

# Script de inicio que usa $PORT de Railway
RUN echo '#!/bin/bash\n\
sed -i "s/listen 80/listen ${PORT:-80}/" /etc/nginx/sites-available/default\n\
php-fpm -D\n\
nginx -g "daemon off;"' > /start.sh
RUN chmod +x /start.sh

# Nginx config
RUN echo 'server {\n\
    listen 80;\n\
    root /var/www/html;\n\
    index index.html index.php;\n\
    location / { try_files $uri $uri/ =404; }\n\
    location ~ \\.php$ {\n\
        fastcgi_pass 127.0.0.1:9000;\n\
        fastcgi_index index.php;\n\
        include fastcgi_params;\n\
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;\n\
    }\n\
}' > /etc/nginx/sites-available/default

EXPOSE 8080
CMD ["/start.sh"]
