<?php
/**
 * Telegram Bot Notification Service
 * 
 * Gửi thông báo cảnh báo qua Telegram Bot API
 * Hỗ trợ:
 * - Cảnh báo nhiệt độ CPU cao
 * - Cảnh báo RAM đầy
 * - Cảnh báo cảm biến IoT (nhiệt độ, độ ẩm bất thường)
 * - Thông báo device offline/online
 */

class TelegramNotifier {
    
    private $botToken;
    private $chatId;
    private $enabled;
    private $apiUrl = 'https://api.telegram.org/bot';
    private $lastNotifications = [];
    private $cooldownMinutes = 5; // Không gửi lại cùng loại cảnh báo trong 5 phút
    
    // Alert thresholds
    private $thresholds = [
        'cpu_temp_high' => 70,      // °C
        'cpu_temp_critical' => 80,   // °C
        'ram_usage_high' => 85,      // %
        'ram_usage_critical' => 95,  // %
        'sensor_temp_high' => 40,    // °C
        'sensor_temp_low' => 5,      // °C
        'sensor_humidity_high' => 90, // %
        'sensor_humidity_low' => 20,  // %
        'battery_low' => 20,         // %
    ];
    
    private $cacheFile;
    
    public function __construct($config = []) {
        $this->botToken = $config['bot_token'] ?? '';
        $this->chatId = $config['chat_id'] ?? '';
        $this->enabled = $config['enabled'] ?? false;
        
        if (isset($config['thresholds'])) {
            $this->thresholds = array_merge($this->thresholds, $config['thresholds']);
        }
        
        if (isset($config['cooldown_minutes'])) {
            $this->cooldownMinutes = $config['cooldown_minutes'];
        }
        
        // Cache file để lưu thời gian gửi notification cuối
        $this->cacheFile = __DIR__ . '/../data/telegram_notifications.json';
        $this->loadNotificationCache();
    }
    
    /**
     * Kiểm tra cấu hình Telegram có hợp lệ không
     */
    public function isConfigured(): bool {
        return $this->enabled && !empty($this->botToken) && !empty($this->chatId);
    }
    
    /**
     * Load cache notification times
     */
    private function loadNotificationCache(): void {
        if (file_exists($this->cacheFile)) {
            $content = file_get_contents($this->cacheFile);
            $this->lastNotifications = json_decode($content, true) ?: [];
        }
    }
    
    /**
     * Save notification cache
     */
    private function saveNotificationCache(): void {
        $dir = dirname($this->cacheFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($this->cacheFile, json_encode($this->lastNotifications));
    }
    
    /**
     * Kiểm tra có nên gửi notification không (cooldown)
     */
    private function shouldSendNotification(string $alertType): bool {
        if (!isset($this->lastNotifications[$alertType])) {
            return true;
        }
        
        $lastTime = $this->lastNotifications[$alertType];
        $cooldownSeconds = $this->cooldownMinutes * 60;
        
        return (time() - $lastTime) >= $cooldownSeconds;
    }
    
    /**
     * Đánh dấu đã gửi notification
     */
    private function markNotificationSent(string $alertType): void {
        $this->lastNotifications[$alertType] = time();
        $this->saveNotificationCache();
    }
    
    /**
     * Gửi tin nhắn qua Telegram Bot API
     */
    public function sendMessage(string $message, bool $parseMode = true): array {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'Telegram not configured'];
        }
        
        $url = $this->apiUrl . $this->botToken . '/sendMessage';
        
        $params = [
            'chat_id' => $this->chatId,
            'text' => $message,
            'disable_web_page_preview' => true,
        ];
        
        if ($parseMode) {
            $params['parse_mode'] = 'HTML';
        }
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return ['success' => false, 'error' => $error];
        }
        
        $result = json_decode($response, true);
        
        if ($httpCode === 200 && isset($result['ok']) && $result['ok']) {
            return ['success' => true, 'message_id' => $result['result']['message_id'] ?? null];
        }
        
        return [
            'success' => false, 
            'error' => $result['description'] ?? 'Unknown error',
            'http_code' => $httpCode
        ];
    }
    
    /**
     * Gửi cảnh báo CPU temperature
     */
    public function alertCpuTemperature(float $temperature): ?array {
        $alertType = 'cpu_temp';
        
        if ($temperature >= $this->thresholds['cpu_temp_critical']) {
            $level = '🔴 CRITICAL';
            $alertType .= '_critical';
        } elseif ($temperature >= $this->thresholds['cpu_temp_high']) {
            $level = '🟡 WARNING';
            $alertType .= '_high';
        } else {
            return null; // Không cần cảnh báo
        }
        
        if (!$this->shouldSendNotification($alertType)) {
            return null;
        }
        
        $message = "🌡️ <b>CPU Temperature Alert</b>\n\n"
                 . "Level: {$level}\n"
                 . "Temperature: <b>{$temperature}°C</b>\n"
                 . "Threshold: {$this->thresholds['cpu_temp_high']}°C\n\n"
                 . "⏰ " . date('Y-m-d H:i:s');
        
        $result = $this->sendMessage($message);
        
        if ($result['success']) {
            $this->markNotificationSent($alertType);
        }
        
        return $result;
    }
    
    /**
     * Gửi cảnh báo RAM usage
     */
    public function alertRamUsage(float $usagePercent): ?array {
        $alertType = 'ram_usage';
        
        if ($usagePercent >= $this->thresholds['ram_usage_critical']) {
            $level = '🔴 CRITICAL';
            $alertType .= '_critical';
        } elseif ($usagePercent >= $this->thresholds['ram_usage_high']) {
            $level = '🟡 WARNING';
            $alertType .= '_high';
        } else {
            return null;
        }
        
        if (!$this->shouldSendNotification($alertType)) {
            return null;
        }
        
        $message = "💾 <b>RAM Usage Alert</b>\n\n"
                 . "Level: {$level}\n"
                 . "Usage: <b>{$usagePercent}%</b>\n"
                 . "Threshold: {$this->thresholds['ram_usage_high']}%\n\n"
                 . "⏰ " . date('Y-m-d H:i:s');
        
        $result = $this->sendMessage($message);
        
        if ($result['success']) {
            $this->markNotificationSent($alertType);
        }
        
        return $result;
    }
    
    /**
     * Gửi cảnh báo sensor IoT
     */
    public function alertSensor(string $deviceId, array $sensorData): ?array {
        $alerts = [];
        $temperature = $sensorData['temperature'] ?? null;
        $humidity = $sensorData['humidity'] ?? null;
        $battery = $sensorData['battery_level'] ?? null;
        
        // Kiểm tra nhiệt độ
        if ($temperature !== null) {
            if ($temperature >= $this->thresholds['sensor_temp_high']) {
                $alerts[] = "🌡️ Temperature HIGH: <b>{$temperature}°C</b> (>{$this->thresholds['sensor_temp_high']}°C)";
            } elseif ($temperature <= $this->thresholds['sensor_temp_low']) {
                $alerts[] = "🌡️ Temperature LOW: <b>{$temperature}°C</b> (<{$this->thresholds['sensor_temp_low']}°C)";
            }
        }
        
        // Kiểm tra độ ẩm
        if ($humidity !== null) {
            if ($humidity >= $this->thresholds['sensor_humidity_high']) {
                $alerts[] = "💧 Humidity HIGH: <b>{$humidity}%</b> (>{$this->thresholds['sensor_humidity_high']}%)";
            } elseif ($humidity <= $this->thresholds['sensor_humidity_low']) {
                $alerts[] = "💧 Humidity LOW: <b>{$humidity}%</b> (<{$this->thresholds['sensor_humidity_low']}%)";
            }
        }
        
        // Kiểm tra pin
        if ($battery !== null && $battery > 0 && $battery <= $this->thresholds['battery_low']) {
            $alerts[] = "🔋 Battery LOW: <b>{$battery}%</b>";
        }
        
        if (empty($alerts)) {
            return null;
        }
        
        $alertType = "sensor_{$deviceId}";
        if (!$this->shouldSendNotification($alertType)) {
            return null;
        }
        
        $message = "📡 <b>Sensor Alert - {$deviceId}</b>\n\n"
                 . implode("\n", $alerts) . "\n\n"
                 . "⏰ " . date('Y-m-d H:i:s');
        
        $result = $this->sendMessage($message);
        
        if ($result['success']) {
            $this->markNotificationSent($alertType);
        }
        
        return $result;
    }
    
    /**
     * Gửi thông báo device offline
     */
    public function alertDeviceOffline(string $deviceId, int $lastSeen = null): ?array {
        $alertType = "device_offline_{$deviceId}";
        
        if (!$this->shouldSendNotification($alertType)) {
            return null;
        }
        
        $lastSeenStr = $lastSeen ? date('Y-m-d H:i:s', $lastSeen) : 'Unknown';
        
        $message = "🔴 <b>Device Offline</b>\n\n"
                 . "Device: <b>{$deviceId}</b>\n"
                 . "Last seen: {$lastSeenStr}\n\n"
                 . "⏰ " . date('Y-m-d H:i:s');
        
        $result = $this->sendMessage($message);
        
        if ($result['success']) {
            $this->markNotificationSent($alertType);
        }
        
        return $result;
    }
    
    /**
     * Gửi thông báo device online
     */
    public function alertDeviceOnline(string $deviceId): ?array {
        // Clear offline notification để có thể gửi lại nếu device offline lần nữa
        $offlineKey = "device_offline_{$deviceId}";
        if (isset($this->lastNotifications[$offlineKey])) {
            unset($this->lastNotifications[$offlineKey]);
            $this->saveNotificationCache();
        }
        
        $alertType = "device_online_{$deviceId}";
        if (!$this->shouldSendNotification($alertType)) {
            return null;
        }
        
        $message = "🟢 <b>Device Online</b>\n\n"
                 . "Device: <b>{$deviceId}</b>\n"
                 . "Status: Connected\n\n"
                 . "⏰ " . date('Y-m-d H:i:s');
        
        $result = $this->sendMessage($message);
        
        if ($result['success']) {
            $this->markNotificationSent($alertType);
        }
        
        return $result;
    }
    
    /**
     * Gửi test message
     */
    public function sendTestMessage(): array {
        $message = "✅ <b>RaspberryDashboard Test</b>\n\n"
                 . "Telegram notification đã được cấu hình thành công!\n\n"
                 . "📊 Dashboard sẽ gửi thông báo khi:\n"
                 . "• CPU temperature quá cao\n"
                 . "• RAM usage quá cao\n"
                 . "• Sensor values bất thường\n"
                 . "• Device offline/online\n\n"
                 . "⏰ " . date('Y-m-d H:i:s');
        
        return $this->sendMessage($message);
    }
    
    /**
     * Lấy thông tin bot
     */
    public function getBotInfo(): array {
        if (empty($this->botToken)) {
            return ['success' => false, 'error' => 'Bot token not configured'];
        }
        
        $url = $this->apiUrl . $this->botToken . '/getMe';
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return ['success' => false, 'error' => $error];
        }
        
        $result = json_decode($response, true);
        
        if (isset($result['ok']) && $result['ok']) {
            return ['success' => true, 'bot' => $result['result']];
        }
        
        return ['success' => false, 'error' => $result['description'] ?? 'Unknown error'];
    }
    
    /**
     * Get thresholds
     */
    public function getThresholds(): array {
        return $this->thresholds;
    }
    
    /**
     * Set threshold
     */
    public function setThreshold(string $key, $value): void {
        if (isset($this->thresholds[$key])) {
            $this->thresholds[$key] = $value;
        }
    }
    
    /**
     * Set cooldown minutes
     */
    public function setCooldownMinutes(int $minutes): void {
        $this->cooldownMinutes = max(1, $minutes);
    }
    
    /**
     * Clear notification cache (reset cooldowns)
     */
    public function clearNotificationCache(): void {
        $this->lastNotifications = [];
        if (file_exists($this->cacheFile)) {
            unlink($this->cacheFile);
        }
    }
}
