FROM php:8.2-apache

# Install PostgreSQL client libraries & PHP database extensions
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpq-dev \
    && docker-php-ext-install mysqli pdo pdo_pgsql pgsql \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html

# Expose HTTP port
EXPOSE 80

# Configure Apache port dynamically if PORT environment variable is provided by Render
CMD ["sh", "-c", "sed -i 's/80/'${PORT:-80}'/g' /etc/apache2/ports.conf /etc/apache2/sites-available/000-default.conf && apache2-foreground"]