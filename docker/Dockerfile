# Use official WordPress image as base
FROM wordpress:latest

# Install system dependencies
RUN apt-get update && apt-get install -y \
    python3-pip \
    ffmpeg \
    && rm -rf /var/lib/apt/lists/*

# Install yt-dlp
RUN pip3 install --upgrade yt-dlp

# Install PHP extensions
RUN docker-php-ext-install \
    pdo \
    pdo_mysql \
    mysqli

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Ensure WordPress files are in place
WORKDIR /var/www/html

# Copy WordPress files from the image's source
RUN cp -r /usr/src/wordpress/* /var/www/html/

# Create and set plugin directory
RUN mkdir -p /var/www/html/wp-content/plugins/video-fact-checker

# Copy plugin files
COPY . /var/www/html/wp-content/plugins/video-fact-checker/

# Install plugin dependencies
WORKDIR /var/www/html/wp-content/plugins/video-fact-checker
RUN composer install --no-dev --optimize-autoloader

# Set permissions
RUN chown -R www-data:www-data /var/www/html && \
    find /var/www/html -type d -exec chmod 755 {} \; && \
    find /var/www/html -type f -exec chmod 644 {} \;

# Enable Apache rewrite module
RUN a2enmod rewrite

# Return to main directory
WORKDIR /var/www/html