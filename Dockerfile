FROM php:8.2-apache

# Habilitar extensiones de base de datos MySQL (mysqli y pdo_mysql)
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Copiar los archivos del proyecto al directorio de Apache
COPY . /var/www/html/

# Configurar Apache para que use el puerto asignado por Render ($PORT)
RUN sed -i 's/80/${PORT}/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf

EXPOSE 80