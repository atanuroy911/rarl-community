# RARL Community Platform — for local Dokploy testing (or any Docker host).
# Plain PHP + Apache, no build step. Pair with docker-compose.yml for a MySQL service.

FROM php:8.2-apache

# Extensions used by the app: pdo_mysql (db), gd (FPDF/phpqrcode image handling)
RUN docker-php-ext-install pdo_mysql gd

# Let .htaccess (routing/security rules) actually take effect
RUN a2enmod rewrite \
    && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

WORKDIR /var/www/html
COPY . /var/www/html

# Uploaded content needs to be writable by the web server user
RUN chown -R www-data:www-data /var/www/html/uploads \
    && chmod -R 775 /var/www/html/uploads

EXPOSE 80
