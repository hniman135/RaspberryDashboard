<?php
/**
 * Telegram API Endpoint
 * 
 * Endpoints:
 * - GET  ?action=status          - Lấy trạng thái cấu hình Telegram
 * - POST ?action=save_config     - Lưu cấu hình Telegram
 * - POST ?action=test            - Gửi test message
 * - GET  ?action=get_config      - Lấy cấu hình hiện tại
 * - POST ?action=clear_cache     - Xóa notification cache
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/TelegramNotifier.php';
require_once __DIR__ . '/../timezone.php';

// Session-based authentication (same as sys_infos.php)
session_start();
if (!isset($_SESSION['rpidbauth'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

// Load config
$config = new Config();
$config->load(__DIR__ . '/../local.config', __DIR__ . '/../defaults.php');

// Get action
$action = $_GET['action'] ?? $_POST['action'] ?? 'status';

// Get Telegram config
$telegramConfig = [
    'enabled' => (bool)$config->get('telegram.enabled'),
    'bot_token' => $config->get('telegram.bot_token') ?: '',
    'chat_id' => $config->get('telegram.chat_id') ?: '',
    'cooldown_minutes' => (int)($config->get('telegram.cooldown_minutes') ?: 5),
    'thresholds' => [
        'cpu_temp_high' => (float)($config->get('telegram.thresholds.cpu_temp_high') ?: 70),
        'cpu_temp_critical' => (float)($config->get('telegram.thresholds.cpu_temp_critical') ?: 80),
        'ram_usage_high' => (float)($config->get('telegram.thresholds.ram_usage_high') ?: 85),
        'ram_usage_critical' => (float)($config->get('telegram.thresholds.ram_usage_critical') ?: 95),
        'sensor_temp_high' => (float)($config->get('telegram.thresholds.sensor_temp_high') ?: 40),
        'sensor_temp_low' => (float)($config->get('telegram.thresholds.sensor_temp_low') ?: 5),
        'sensor_humidity_high' => (float)($config->get('telegram.thresholds.sensor_humidity_high') ?: 90),
        'sensor_humidity_low' => (float)($config->get('telegram.thresholds.sensor_humidity_low') ?: 20),
        'battery_low' => (float)($config->get('telegram.thresholds.battery_low') ?: 20),
    ],
];

$notifier = new TelegramNotifier($telegramConfig);

try {
    switch ($action) {
        case 'status':
            // Lấy trạng thái cấu hình
            $response = [
                'success' => true,
                'configured' => $notifier->isConfigured(),
                'enabled' => $telegramConfig['enabled'],
                'has_token' => !empty($telegramConfig['bot_token']),
                'has_chat_id' => !empty($telegramConfig['chat_id']),
            ];
            
            // Nếu có token, thử lấy thông tin bot
            if (!empty($telegramConfig['bot_token'])) {
                $botInfo = $notifier->getBotInfo();
                if ($botInfo['success']) {
                    $response['bot_name'] = $botInfo['bot']['first_name'] ?? '';
                    $response['bot_username'] = $botInfo['bot']['username'] ?? '';
                }
            }
            break;
            
        case 'get_config':
            // Lấy cấu hình (ẩn một phần token)
            $maskedToken = '';
            if (!empty($telegramConfig['bot_token'])) {
                $token = $telegramConfig['bot_token'];
                $maskedToken = substr($token, 0, 10) . '...' . substr($token, -5);
            }
            
            $response = [
                'success' => true,
                'config' => [
                    'enabled' => $telegramConfig['enabled'],
                    'bot_token_masked' => $maskedToken,
                    'chat_id' => $telegramConfig['chat_id'],
                    'cooldown_minutes' => $telegramConfig['cooldown_minutes'],
                    'thresholds' => $telegramConfig['thresholds'],
                ]
            ];
            break;
            
        case 'save_config':
            // Lưu cấu hình
            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input) {
                $input = $_POST;
            }
            
            // Get existing telegram config
            $existingTelegram = $config->get('telegram') ?: [];
            $existingToken = $existingTelegram['bot_token'] ?? '';
            
            // If bot_token is empty, keep the existing one
            $newBotToken = !empty($input['bot_token']) ? $input['bot_token'] : $existingToken;
            
            // Validate required fields
            if (isset($input['enabled']) && $input['enabled']) {
                if (empty($newBotToken)) {
                    throw new Exception('Bot token is required');
                }
                if (empty($input['chat_id'])) {
                    throw new Exception('Chat ID is required');
                }
            }
            
            // Prepare config to save
            $newConfig = $config->userconf;
            
            $newConfig['telegram'] = [
                'enabled' => (bool)($input['enabled'] ?? false),
                'bot_token' => $newBotToken,
                'chat_id' => $input['chat_id'] ?? '',
                'cooldown_minutes' => (int)($input['cooldown_minutes'] ?? 5),
            ];
            
            // Save thresholds if provided
            if (isset($input['thresholds']) && is_array($input['thresholds'])) {
                $newConfig['telegram']['thresholds'] = [];
                $allowedThresholds = [
                    'cpu_temp_high', 'cpu_temp_critical',
                    'ram_usage_high', 'ram_usage_critical',
                    'sensor_temp_high', 'sensor_temp_low',
                    'sensor_humidity_high', 'sensor_humidity_low',
                    'battery_low'
                ];
                
                foreach ($allowedThresholds as $key) {
                    if (isset($input['thresholds'][$key])) {
                        $newConfig['telegram']['thresholds'][$key] = (float)$input['thresholds'][$key];
                    }
                }
            }
            
            $result = $config->save($newConfig);
            
            if ($result === true) {
                $response = ['success' => true, 'message' => 'Configuration saved'];
            } elseif ($result === 'perm_error') {
                throw new Exception('Permission denied: Cannot write config file');
            } else {
                throw new Exception('Failed to save configuration');
            }
            break;
            
        case 'test':
            // Gửi test message
            if (!$notifier->isConfigured()) {
                throw new Exception('Telegram not configured. Please save configuration first.');
            }
            
            $result = $notifier->sendTestMessage();
            
            if ($result['success']) {
                $response = ['success' => true, 'message' => 'Test message sent successfully'];
            } else {
                throw new Exception('Failed to send message: ' . ($result['error'] ?? 'Unknown error'));
            }
            break;
            
        case 'clear_cache':
            // Xóa notification cache
            $notifier->clearNotificationCache();
            $response = ['success' => true, 'message' => 'Notification cache cleared'];
            break;
            
        case 'get_bot_info':
            // Lấy thông tin bot
            if (empty($telegramConfig['bot_token'])) {
                throw new Exception('Bot token not configured');
            }
            
            $botInfo = $notifier->getBotInfo();
            if ($botInfo['success']) {
                $response = [
                    'success' => true,
                    'bot' => $botInfo['bot']
                ];
            } else {
                throw new Exception($botInfo['error'] ?? 'Failed to get bot info');
            }
            break;
            
        default:
            throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    http_response_code(400);
    $response = [
        'success' => false,
        'error' => $e->getMessage()
    ];
}

$response['timestamp'] = time();
echo json_encode($response, JSON_PRETTY_PRINT);
