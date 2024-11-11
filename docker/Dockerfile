FROM php:8.1-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    python3-pip \
    ffmpeg \
    libzip-dev \
    wget \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install \
    pdo \
    pdo_mysql \
    mysqli \
    zip

# Install yt-dlp
RUN pip3 install --upgrade yt-dlp

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Download and extract WordPress
WORKDIR /var/www/html
RUN wget https://wordpress.org/wordpress-6.4.2.tar.gz \
    && tar -xzf wordpress-6.4.2.tar.gz --strip-components=1 \
    && rm wordpress-6.4.2.tar.gz

# Copy plugin files
COPY . /var/www/html/wp-content/plugins/video-fact-checker/

# Install plugin dependencies
WORKDIR /var/www/html/wp-content/plugins/video-fact-checker
RUN composer install --no-dev --optimize-autoloader

# Set permissions
RUN chown -R www-data:www-data /var/www/html

# Enable Apache rewrite
RUN a2enmod rewrite

# Return to web root
WORKDIR /var/www/html