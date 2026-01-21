FROM php:8.2-apache

WORKDIR /var/www/html

COPY . .

RUN a2enmod rewrite
RUN chown -R www-data:www-data /var/www/html
RUN chmod -R 755 /var/www/html

EXPOSE 80
