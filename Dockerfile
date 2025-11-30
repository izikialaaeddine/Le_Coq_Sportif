# Dockerfile pour déploiement PHP
FROM php:8.1-apache

# Installer les dépendances système pour PostgreSQL
RUN apt-get update && apt-get install -y \
    libpq-dev \
    && docker-php-ext-install mysqli pdo pdo_mysql \
    && docker-php-ext-configure pgsql -with-pgsql=/usr/local/pgsql \
    && docker-php-ext-install pdo_pgsql pgsql \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Activer mod_rewrite
RUN a2enmod rewrite

# Configurer ServerName pour éviter le warning
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Désactiver le site par défaut et activer notre configuration
RUN a2dissite 000-default.conf

# Copier les fichiers
COPY . /var/www/html/

# Copier la configuration Apache personnalisée
COPY apache-config.conf /etc/apache2/sites-available/000-default.conf
RUN a2ensite 000-default.conf

# Configurer les permissions
RUN chown -R www-data:www-data /var/www/html
RUN chmod -R 755 /var/www/html
RUN chmod -R 644 /var/www/html/photos/*.png /var/www/html/photos/*.jpg /var/www/html/photos/*.svg 2>/dev/null || true

# Exposer le port
EXPOSE 80

# Démarrer Apache
CMD ["apache2-foreground"]
