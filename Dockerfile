FROM wordpress:latest

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    curl \
    wget \
    vim \
    less \
    && rm -rf /var/lib/apt/lists/*

# Install XDebug for debugging
RUN pecl install xdebug \
    && docker-php-ext-enable xdebug

# Configure XDebug
RUN echo "xdebug.mode=debug" >> /usr/local/etc/php/conf.d/xdebug.ini \
    && echo "xdebug.start_with_request=yes" >> /usr/local/etc/php/conf.d/xdebug.ini \
    && echo "xdebug.client_host=host.docker.internal" >> /usr/local/etc/php/conf.d/xdebug.ini \
    && echo "xdebug.client_port=9003" >> /usr/local/etc/php/conf.d/xdebug.ini \
    && echo "xdebug.log=/tmp/xdebug.log" >> /usr/local/etc/php/conf.d/xdebug.ini

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Install WP-CLI
RUN curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
    && chmod +x wp-cli.phar \
    && mv wp-cli.phar /usr/local/bin/wp

# Create development directories
RUN mkdir -p /var/www/html/wp-content/plugins/puntwork \
    && mkdir -p /var/www/html/wp-content/uploads \
    && chown -R www-data:www-data /var/www/html/wp-content

# Set working directory
WORKDIR /var/www/html

# Expose port 80
EXPOSE 80

# Default command
CMD ["apache2-foreground"]