FROM wordpress:latest

# Install system dependencies
RUN apt-get update && apt-get install -y \
    python3-pip \
    ffmpeg \
    && rm -rf /var/lib/apt/lists/*

# Install yt-dlp
RUN pip3 install --upgrade yt-dlp

# Install PHP extensions needed by WordPress and the plugin
RUN docker-php-ext-install \
    pdo \
    pdo_mysql \
    mysqli

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Copy plugin files
COPY . /var/www/html/wp-content/plugins/video-fact-checker/

# Set working directory
WORKDIR /var/www/html/wp-content/plugins/video-fact-checker

# Install plugin dependencies
RUN composer install --no-dev --optimize-autoloader

# Set permissions
RUN chown -R www-data:www-data /var/www/html/wp-content