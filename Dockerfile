# PHP va Apache asosida
FROM php:8.2-apache

# Kerakli paketlar
RUN apt-get update && \
    apt-get install -y unzip git curl vim && \
    rm -rf /var/lib/apt/lists/*

# Web rootga KinoBot fayllarini qo‘yish
COPY . /var/www/html/

# Ish papkasi
WORKDIR /var/www/html

# PHP xatolarni ko‘rsatish (dev uchun)
RUN echo "error_reporting = E_ALL" >> /usr/local/etc/php/conf.d/docker-php-errors.ini
RUN echo "display_errors = On" >> /usr/local/etc/php/conf.d/docker-php-errors.ini

# Apache mod_rewrite yoqish (agar kerak bo‘lsa)
RUN a2enmod rewrite

# Port
EXPOSE 80

# Ishga tushirish
CMD ["apache2-foreground"] 
