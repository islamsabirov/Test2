# ============================================================
#  KinoBot — Dockerfile
#  Render.com, VPS, Docker Compose bilan ishlaydi
# ============================================================

FROM php:8.2-apache

# Apache xatolikni bartaraf etish
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# PHP kengaytmalari
RUN docker-php-ext-install opcache
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    && docker-php-ext-install curl \
    && rm -rf /var/lib/apt/lists/*

# PHP sozlamalari
RUN echo "upload_max_filesize = 50M" >> /usr/local/etc/php/conf.d/custom.ini \
 && echo "post_max_size = 50M"       >> /usr/local/etc/php/conf.d/custom.ini \
 && echo "max_execution_time = 120"  >> /usr/local/etc/php/conf.d/custom.ini \
 && echo "memory_limit = 256M"       >> /usr/local/etc/php/conf.d/custom.ini \
 && echo "display_errors = Off"      >> /usr/local/etc/php/conf.d/custom.ini \
 && echo "log_errors = On"           >> /usr/local/etc/php/conf.d/custom.ini

# Loyiha fayllarini ko'chirish
WORKDIR /var/www/html
COPY . /var/www/html/

# Runtime papkalarini yaratish va ruxsat berish
RUN mkdir -p users step kino tizim admin \
 && chown -R www-data:www-data /var/www/html \
 && chmod -R 755 /var/www/html \
 && chmod -R 777 users step kino tizim admin

# Apache mod_rewrite yoqish
RUN a2enmod rewrite

# .htaccess ishlasin uchun
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# Render PORT sozlamasi (default 10000)
ENV PORT=10000
EXPOSE ${PORT}

# Apache ni PORT bilan ishga tushirish
CMD bash -c "sed -i 's/Listen 80/Listen ${PORT}/' /etc/apache2/ports.conf && \
             sed -i 's/:80>/:${PORT}>/' /etc/apache2/sites-enabled/000-default.conf && \
             apache2-foreground"
