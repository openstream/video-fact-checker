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

# Create workspace directory
RUN mkdir -p /workspace/wp-content/plugins

# Download and extract latest WordPress to workspace
WORKDIR /workspace
RUN wget https://wordpress.org/latest.tar.gz \
    && tar -xzf latest.tar.gz \
    && rm latest.tar.gz \
    && mv wordpress/* . \
    && rmdir wordpress \
    && echo "WordPress files:" \
    && ls -la /workspace

# Copy plugin files to correct location
COPY . /workspace/wp-content/plugins/video-fact-checker/

# Install plugin dependencies
WORKDIR /workspace/wp-content/plugins/video-fact-checker
RUN composer install --no-dev --optimize-autoloader

# Set permissions
RUN chown -R www-data:www-data /workspace

# Enable Apache rewrite
RUN a2enmod rewrite
# Return to workspace
WORKDIR /workspace