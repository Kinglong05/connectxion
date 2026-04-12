FROM php:8.1-apache

# Install MySQLi extension
RUN docker-php-ext-install mysqli && docker-php-ext-enable mysqli

# Enable Apache mod_rewrite for friendly URLs if needed
RUN a2enmod rewrite

# Set the working directory
WORKDIR /var/www/html

# Copy your application files
COPY . .

# Ensure permissions are correct
RUN chown -R www-data:www-data /var/www/html

# Expose port 80
EXPOSE 80
