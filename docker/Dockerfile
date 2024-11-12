FROM php:8.1-apache

# Install system dependencies
RUN echo "Updating package lists..." \
    && apt-get update \
    && echo "Installing system dependencies..." \
    && apt-get install -y \
        python3-pip \
        ffmpeg \
        libzip-dev \
        wget \
        tree \
    && rm -rf /var/lib/apt/lists/* \
    && echo "System dependencies installed."

# Install PHP extensions
RUN echo "Installing PHP extensions..." \
    && docker-php-ext-install \
        pdo \
        pdo_mysql \
        mysqli \
        zip \
    && echo "PHP extensions installed."

# Install yt-dlp
RUN echo "Installing yt-dlp..." \
    && pip3 install --upgrade yt-dlp \
    && echo "yt-dlp installed."

# Install Composer
RUN echo "Installing Composer..." \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && echo "Composer installed."

# Set working directory
WORKDIR /workspace

# Download and extract latest WordPress to workspace
RUN echo "Downloading WordPress..." \
    && wget https://wordpress.org/latest.tar.gz \
    && echo "Extracting WordPress..." \
    && tar -xzf latest.tar.gz \
    && rm latest.tar.gz \
    && mv wordpress/* . \
    && rmdir wordpress \
    && echo "WordPress files extracted:" \
    && ls -la /workspace

# Copy plugin files to the WordPress plugins directory
RUN echo "Copying plugin files..." \
    && COPY . /workspace/wp-content/plugins/video-fact-checker/ \
    && echo "Plugin files copied."

# Install plugin dependencies
WORKDIR /workspace/wp-content/plugins/video-fact-checker
RUN echo "Installing plugin dependencies..." \
    && composer install --no-dev --optimize-autoloader \
    && echo "Plugin dependencies installed."

# Set permissions
RUN echo "Setting permissions..." \
    && chown -R www-data:www-data /workspace \
    && echo "Permissions set."

# Enable Apache rewrite
RUN echo "Enabling Apache rewrite module..." \
    && a2enmod rewrite \
    && echo "Apache rewrite module enabled."

# Return to workspace
WORKDIR /workspace