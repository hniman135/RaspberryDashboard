#!/bin/bash
# Docker Entrypoint Script for RaspberryDashboard
# ================================================

set -e

echo "============================================"
echo "  RaspberryDashboard Docker Container"
echo "============================================"

# Create data directory if not exists
if [ ! -d "/var/www/html/data" ]; then
    echo "[INIT] Creating data directory..."
    mkdir -p /var/www/html/data
    chown -R www-data:www-data /var/www/html/data
    chmod 775 /var/www/html/data
fi

# Create config directory if not exists (for persistent config)
if [ ! -d "/var/www/html/config" ]; then
    echo "[INIT] Creating config directory..."
    mkdir -p /var/www/html/config
    chown -R www-data:www-data /var/www/html/config
    chmod 775 /var/www/html/config
fi

# Handle local.config - use persistent config volume
if [ -d "/var/www/html/local.config" ]; then
    echo "[INIT] Removing invalid local.config directory..."
    rm -rf /var/www/html/local.config
fi

# Check if persistent local.config exists in config volume
if [ -f "/var/www/html/config/local.config" ]; then
    echo "[INIT] Using persistent local.config from config volume..."
    ln -sf /var/www/html/config/local.config /var/www/html/local.config
elif [ ! -f "/var/www/html/local.config" ]; then
    echo "[INIT] Creating default local.config in config volume..."
    cat > /var/www/html/config/local.config << 'EOF'
<?php
return array();
?>
EOF
    chown www-data:www-data /var/www/html/config/local.config
    chmod 664 /var/www/html/config/local.config
    ln -sf /var/www/html/config/local.config /var/www/html/local.config
fi

# Update MQTT configuration from environment variables
if [ -n "$MQTT_BROKER" ]; then
    echo "[CONFIG] MQTT Broker: $MQTT_BROKER"
fi

if [ -n "$MQTT_USER" ] && [ -n "$MQTT_PASSWORD" ]; then
    echo "[CONFIG] Setting up MQTT authentication..."
    # Create password file for Mosquitto
    mosquitto_passwd -b -c /etc/mosquitto/passwd "$MQTT_USER" "$MQTT_PASSWORD" 2>/dev/null || true
fi

# Ensure Mosquitto directories exist with proper permissions
mkdir -p /var/log/mosquitto /var/lib/mosquitto /run/mosquitto
chown -R mosquitto:mosquitto /var/log/mosquitto /var/lib/mosquitto /run/mosquitto

# Ensure data directory permissions for www-data (for MQTT subscriber logs and database)
echo "[INIT] Setting up data directory permissions..."
touch /var/www/html/data/mqtt_subscriber.log
touch /var/www/html/data/mqtt_subscriber_error.log
chown -R www-data:www-data /var/www/html/data
chmod 775 /var/www/html/data
chmod 664 /var/www/html/data/mqtt_subscriber*.log

# Ensure supervisor log directory exists
mkdir -p /var/log/supervisor

echo "[INIT] Starting services via Supervisor..."
echo "============================================"
echo "  Dashboard: http://localhost:8080"
echo "  MQTT:      localhost:1883"
echo "============================================"

# Execute the main command (supervisord)
exec "$@"
