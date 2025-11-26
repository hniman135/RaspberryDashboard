#!/usr/bin/env php
<?php
/**
 * MQTT Subscriber Service for ESP32 IoT Gateway
 * 
 * This script runs as a background service on Raspberry Pi to:
 * - Subscribe to MQTT topics from ESP32 devices
 * - Store sensor data in SQLite database
 * - Handle offline buffering
 * - Provide data for dashboard API
 * - Send Telegram notifications for alerts
 * 
 * Run as: php mqtt_subscriber.php
 * Or as service: sudo systemctl start mqtt-subscriber
 */

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/TelegramNotifier.php';

// MQTT Configuration
define('MQTT_BROKER', '127.0.0.1');
define('MQTT_PORT', 1883);
define('MQTT_USER', 'iot_user');
define('MQTT_PASSWORD', 'iot_password');
define('MQTT_TOPIC', 'home/sensors/#'); // Subscribe to all sensor topics
define('MQTT_QOS', 1);

// Database Configuration
define('DB_PATH', __DIR__ . '/../data/iot_sensors.db');
define('MAX_RECORDS', 10000); // Maximum records to keep in database

// Logging
define('LOG_FILE', __DIR__ . '/../data/mqtt_subscriber.log');
define('LOG_LEVEL', 'INFO'); // DEBUG, INFO, WARNING, ERROR

class MQTTSubscriber {
    private $db;
    private $logFile;
    private $startTime;
    private $messageCount = 0;
    private $telegramNotifier;
    private $config;
    private $devicePreviousStatus = []; // Track previous device status for online/offline alerts
    private $deviceLastDataTime = [];   // Track last time device sent sensor data
    private $configFile;
    private $configLastModified = 0;
    private $configCheckInterval = 30;  // Check config every 30 seconds
    private $lastConfigCheck = 0;
    
    public function __construct() {
        $this->startTime = time();
        $this->logFile = fopen(LOG_FILE, 'a');
        $this->log('INFO', 'MQTT Subscriber started');
        
        // Load config
        $this->configFile = __DIR__ . '/../local.config';
        $this->loadConfig();
        
        // Initialize database
        $this->initDatabase();
        
        // Setup signal handlers for graceful shutdown
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'shutdown']);
            pcntl_signal(SIGINT, [$this, 'shutdown']);
        }
    }
    
    private function loadConfig() {
        $this->config = new Config();
        $this->config->load($this->configFile, __DIR__ . '/../defaults.php');
        $this->configLastModified = file_exists($this->configFile) ? filemtime($this->configFile) : 0;
        $this->lastConfigCheck = time();
        
        // Initialize Telegram notifier
        $this->initTelegramNotifier();
    }
    
    private function checkConfigReload() {
        // Only check periodically to avoid excessive file access
        if (time() - $this->lastConfigCheck < $this->configCheckInterval) {
            return;
        }
        
        $this->lastConfigCheck = time();
        
        if (!file_exists($this->configFile)) {
            return;
        }
        
        $currentModified = filemtime($this->configFile);
        clearstatcache(true, $this->configFile);
        
        if ($currentModified > $this->configLastModified) {
            $this->log('INFO', 'Config file changed, reloading...');
            $this->loadConfig();
            $this->log('INFO', 'Config reloaded successfully');
        }
    }
    
    private function initTelegramNotifier() {
        $telegramConfig = [
            'enabled' => (bool)$this->config->get('telegram.enabled'),
            'bot_token' => $this->config->get('telegram.bot_token') ?: '',
            'chat_id' => $this->config->get('telegram.chat_id') ?: '',
            'cooldown_minutes' => (int)($this->config->get('telegram.cooldown_minutes') ?: 5),
            'thresholds' => [
                'cpu_temp_high' => (float)($this->config->get('telegram.thresholds.cpu_temp_high') ?: 70),
                'cpu_temp_critical' => (float)($this->config->get('telegram.thresholds.cpu_temp_critical') ?: 80),
                'ram_usage_high' => (float)($this->config->get('telegram.thresholds.ram_usage_high') ?: 85),
                'ram_usage_critical' => (float)($this->config->get('telegram.thresholds.ram_usage_critical') ?: 95),
                'sensor_temp_high' => (float)($this->config->get('telegram.thresholds.sensor_temp_high') ?: 40),
                'sensor_temp_low' => (float)($this->config->get('telegram.thresholds.sensor_temp_low') ?: 5),
                'sensor_humidity_high' => (float)($this->config->get('telegram.thresholds.sensor_humidity_high') ?: 90),
                'sensor_humidity_low' => (float)($this->config->get('telegram.thresholds.sensor_humidity_low') ?: 20),
                'battery_low' => (float)($this->config->get('telegram.thresholds.battery_low') ?: 20),
            ],
        ];
        
        $this->telegramNotifier = new TelegramNotifier($telegramConfig);
        
        if ($this->telegramNotifier->isConfigured()) {
            $this->log('INFO', 'Telegram notifications enabled');
        } else {
            $this->log('INFO', 'Telegram notifications disabled or not configured');
        }
    }
    
    private function initDatabase() {
        try {
            // Create data directory if not exists
            $dataDir = dirname(DB_PATH);
            if (!is_dir($dataDir)) {
                mkdir($dataDir, 0755, true);
            }
            
            $this->db = new PDO('sqlite:' . DB_PATH);
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Create tables
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS sensor_data (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    device_id TEXT NOT NULL,
                    temperature REAL,
                    humidity REAL,
                    battery_level REAL,
                    rssi INTEGER,
                    timestamp INTEGER,
                    received_at INTEGER NOT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ");
            
            $this->db->exec("
                CREATE INDEX IF NOT EXISTS idx_device_timestamp 
                ON sensor_data(device_id, timestamp DESC)
            ");
            
            $this->db->exec("
                CREATE INDEX IF NOT EXISTS idx_received_at 
                ON sensor_data(received_at DESC)
            ");
            
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS device_status (
                    device_id TEXT PRIMARY KEY,
                    status TEXT NOT NULL,
                    last_seen INTEGER NOT NULL,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ");
            
            $this->log('INFO', 'Database initialized: ' . DB_PATH);
        } catch (PDOException $e) {
            $this->log('ERROR', 'Database initialization failed: ' . $e->getMessage());
            die("Database error\n");
        }
    }
    
    public function subscribe() {
        $this->log('INFO', 'Connecting to MQTT broker: ' . MQTT_BROKER . ':' . MQTT_PORT);
        
        // Using mosquitto_sub command for simplicity
        // In production, use PHP MQTT library like phpMQTT
        $command = sprintf(
            'mosquitto_sub -h %s -p %d -u %s -P %s -t "%s" -q %d -v 2>&1',
            MQTT_BROKER,
            MQTT_PORT,
            MQTT_USER,
            MQTT_PASSWORD,
            MQTT_TOPIC,
            MQTT_QOS
        );
        
        $this->log('INFO', 'Subscribing to topic: ' . MQTT_TOPIC);
        
        $process = popen($command, 'r');
        
        if (!$process) {
            $this->log('ERROR', 'Failed to start mosquitto_sub');
            die("Failed to subscribe\n");
        }
        
        $this->log('INFO', 'Successfully subscribed, waiting for messages...');
        
        while (!feof($process)) {
            $line = fgets($process);
            if ($line === false) continue;
            
            $line = trim($line);
            if (empty($line)) continue;
            
            // Check if config needs to be reloaded
            $this->checkConfigReload();
            
            // Parse mosquitto_sub output: "topic payload"
            $parts = explode(' ', $line, 2);
            if (count($parts) == 2) {
                $topic = $parts[0];
                $payload = $parts[1];
                $this->handleMessage($topic, $payload);
            }
            
            // Allow signal handling
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }
        
        pclose($process);
        $this->log('WARNING', 'MQTT subscription ended');
    }
    
    private function handleMessage($topic, $payload) {
        $this->messageCount++;
        $this->log('DEBUG', "Message [$this->messageCount] on topic: $topic");
        
        // Parse JSON payload
        $data = json_decode($payload, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log('WARNING', 'Invalid JSON payload: ' . json_last_error_msg());
            return;
        }
        
        // Handle status messages
        if (strpos($topic, '/status') !== false) {
            $this->updateDeviceStatus($data);
            return;
        }
        
        // Handle sensor data
        $this->storeSensorData($data);
    }
    
    private function storeSensorData($data) {
        try {
            // Validate required fields
            if (!isset($data['device_id']) || !isset($data['temperature']) || !isset($data['humidity'])) {
                $this->log('WARNING', 'Missing required fields in sensor data');
                return;
            }
            
            $stmt = $this->db->prepare("
                INSERT INTO sensor_data 
                (device_id, temperature, humidity, battery_level, rssi, timestamp, received_at)
                VALUES (:device_id, :temperature, :humidity, :battery_level, :rssi, :timestamp, :received_at)
            ");
            
            $stmt->execute([
                ':device_id' => $data['device_id'],
                ':temperature' => $data['temperature'],
                ':humidity' => $data['humidity'],
                ':battery_level' => $data['battery_level'] ?? null,
                ':rssi' => $data['rssi'] ?? null,
                ':timestamp' => $data['timestamp'] ?? time() * 1000,
                ':received_at' => time()
            ]);
            
            $this->log('INFO', sprintf(
                'Stored data from %s: T=%.1f°C, H=%.1f%%, B=%.1f%%',
                $data['device_id'],
                $data['temperature'],
                $data['humidity'],
                $data['battery_level'] ?? 0
            ));
            
            // Send Telegram alert if thresholds exceeded
            $this->checkAndSendSensorAlert($data);
            
            // Track last data time for this device
            $this->deviceLastDataTime[$data['device_id']] = time();
            
            // Update device status
            $this->updateDeviceStatus([
                'device_id' => $data['device_id'],
                'status' => 'online'
            ]);
            
            // Cleanup old records
            $this->cleanupOldRecords();
            
        } catch (PDOException $e) {
            $this->log('ERROR', 'Failed to store sensor data: ' . $e->getMessage());
        }
    }
    
    private function checkAndSendSensorAlert($data) {
        if (!$this->telegramNotifier->isConfigured()) {
            return;
        }
        
        $deviceId = $data['device_id'];
        $sensorData = [
            'temperature' => $data['temperature'] ?? null,
            'humidity' => $data['humidity'] ?? null,
            'battery_level' => $data['battery_level'] ?? null,
        ];
        
        $result = $this->telegramNotifier->alertSensor($deviceId, $sensorData);
        
        if ($result && $result['success']) {
            $this->log('INFO', "Telegram alert sent for device: $deviceId");
        }
    }
    
    private function updateDeviceStatus($data) {
        try {
            if (!isset($data['device_id'])) {
                return;
            }
            
            $deviceId = $data['device_id'];
            $newStatus = $data['status'] ?? 'online';
            $previousStatus = $this->devicePreviousStatus[$deviceId] ?? null;
            
            // Ignore offline status if we received sensor data recently (within 30 seconds)
            // This prevents false offline alerts from LWT messages when device is actually online
            if ($newStatus === 'offline') {
                $lastDataTime = $this->deviceLastDataTime[$deviceId] ?? 0;
                $timeSinceLastData = time() - $lastDataTime;
                if ($timeSinceLastData < 30) {
                    $this->log('DEBUG', sprintf(
                        'Ignoring offline status for %s (received data %ds ago)',
                        $deviceId,
                        $timeSinceLastData
                    ));
                    return;
                }
            }
            
            $stmt = $this->db->prepare("
                INSERT OR REPLACE INTO device_status (device_id, status, last_seen, updated_at)
                VALUES (:device_id, :status, :last_seen, datetime('now'))
            ");
            
            $stmt->execute([
                ':device_id' => $deviceId,
                ':status' => $newStatus,
                ':last_seen' => time()
            ]);
            
            $this->log('INFO', sprintf(
                'Device %s status: %s',
                $deviceId,
                $newStatus
            ));
            
            // Send Telegram notification on status change
            if ($this->telegramNotifier->isConfigured() && $previousStatus !== null && $previousStatus !== $newStatus) {
                if ($newStatus === 'offline') {
                    $result = $this->telegramNotifier->alertDeviceOffline($deviceId, time());
                    if ($result && $result['success']) {
                        $this->log('INFO', "Telegram offline alert sent for device: $deviceId");
                    }
                } elseif ($newStatus === 'online' && $previousStatus === 'offline') {
                    $result = $this->telegramNotifier->alertDeviceOnline($deviceId);
                    if ($result && $result['success']) {
                        $this->log('INFO', "Telegram online alert sent for device: $deviceId");
                    }
                }
            }
            
            // Update tracked status
            $this->devicePreviousStatus[$deviceId] = $newStatus;
            
        } catch (PDOException $e) {
            $this->log('ERROR', 'Failed to update device status: ' . $e->getMessage());
        }
    }
    
    private function cleanupOldRecords() {
        try {
            $count = $this->db->query("SELECT COUNT(*) FROM sensor_data")->fetchColumn();
            
            if ($count > MAX_RECORDS) {
                $deleteCount = $count - MAX_RECORDS;
                $this->db->exec("
                    DELETE FROM sensor_data 
                    WHERE id IN (
                        SELECT id FROM sensor_data 
                        ORDER BY received_at ASC 
                        LIMIT $deleteCount
                    )
                ");
                $this->log('INFO', "Cleaned up $deleteCount old records");
            }
        } catch (PDOException $e) {
            $this->log('ERROR', 'Cleanup failed: ' . $e->getMessage());
        }
    }
    
    private function log($level, $message) {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] [$level] $message\n";
        
        // Write to log file
        fwrite($this->logFile, $logMessage);
        fflush($this->logFile);
        
        // Also output to console
        echo $logMessage;
    }
    
    public function shutdown() {
        $uptime = time() - $this->startTime;
        $this->log('INFO', sprintf(
            'Shutting down... Uptime: %ds, Messages processed: %d',
            $uptime,
            $this->messageCount
        ));
        
        if ($this->logFile) {
            fclose($this->logFile);
        }
        
        exit(0);
    }
}

// Run subscriber
$subscriber = new MQTTSubscriber();
$subscriber->subscribe();
