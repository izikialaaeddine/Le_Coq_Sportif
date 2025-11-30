# Dockerfile pour déploiement PHP
FROM php:8.1-apache

# Installer les extensions nécessaires
RUN docker-php-ext-install mysqli pdo pdo_mysql pdo_pgsql

# Activer mod_rewrite
RUN a2enmod rewrite

# Copier les fichiers
COPY . /var/www/html/

# Configurer les permissions
RUN chown -R www-data:www-data /var/www/html
RUN chmod -R 755 /var/www/html

# Exposer le port
EXPOSE 80

# Démarrer Apache
CMD ["apache2-foreground"]

