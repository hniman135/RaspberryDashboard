# RaspberryDashboard Docker Image
# Multi-stage build for IoT Gateway Dashboard

FROM php:8.2-apache

LABEL maintainer="hniman135"
LABEL description="Raspberry Pi Dashboard with IoT Gateway"
LABEL version="1.0"

# Install system dependencies
# Note: libraspberrypi-bin provides vcgencmd for hardware info on Raspberry Pi
RUN apt-get update && apt-get install -y \
    mosquitto \
    mosquitto-clients \
    sqlite3 \
    libsqlite3-dev \
    procps \
    supervisor \
    curl \
    && rm -rf /var/lib/apt/lists/*

# Try to install Raspberry Pi userland tools (vcgencmd)
# This only works on arm64/armhf architecture, will fail silently on others
RUN apt-get update && apt-get install -y libraspberrypi-bin 2>/dev/null || true \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_sqlite

# Enable Apache modules
RUN a2enmod rewrite headers

# Configure Apache
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# Set timezone
ENV TZ=Asia/Ho_Chi_Minh
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

# Create necessary directories
RUN mkdir -p /var/www/html/data \
    && mkdir -p /var/log/mosquitto \
    && mkdir -p /var/lib/mosquitto \
    && mkdir -p /run/mosquitto \
    && chown -R www-data:www-data /var/www/html \
    && chown -R mosquitto:mosquitto /var/log/mosquitto /var/lib/mosquitto /run/mosquitto

# Copy application files
COPY --chown=www-data:www-data . /var/www/html/

# Create data directory with proper permissions
RUN mkdir -p /var/www/html/data \
    && chown -R www-data:www-data /var/www/html/data \
    && chmod 775 /var/www/html/data

# Copy configuration files
COPY docker/mosquitto.conf /etc/mosquitto/mosquitto.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Expose ports
# 80: Apache Web Server
# 1883: MQTT Broker
EXPOSE 80 1883

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 \
    CMD curl -f http://localhost/ || exit 1

# Entrypoint and command
ENTRYPOINT ["/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
