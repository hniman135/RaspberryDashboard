#!/usr/bin/env php
<?php
/**
 * System Monitor - Check CPU/RAM and send Telegram alerts
 * 
 * Run periodically via cron or supervisor
 * Usage: php system_monitor.php
 */

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/TelegramNotifier.php';

// Load config
$config = new Config();
$config->load(__DIR__ . '/../local.config', __DIR__ . '/../defaults.php');

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
    ],
];

$notifier = new TelegramNotifier($telegramConfig);

if (!$notifier->isConfigured()) {
    echo "[" . date('Y-m-d H:i:s') . "] Telegram not configured, skipping...\n";
    exit(0);
}

echo "[" . date('Y-m-d H:i:s') . "] System Monitor running...\n";

// Get CPU temperature
$cpuTemp = 0;
$tempFile = '/sys/class/thermal/thermal_zone0/temp';
if (file_exists($tempFile)) {
    $cpuTemp = (float)trim(file_get_contents($tempFile)) / 1000;
}

echo "[" . date('Y-m-d H:i:s') . "] CPU Temperature: {$cpuTemp}°C\n";

// Check CPU temperature alert
$result = $notifier->alertCpuTemperature($cpuTemp);
if ($result) {
    if ($result['success']) {
        echo "[" . date('Y-m-d H:i:s') . "] ✓ CPU temperature alert sent!\n";
    } else {
        echo "[" . date('Y-m-d H:i:s') . "] ✗ Failed to send CPU alert: " . ($result['error'] ?? 'unknown') . "\n";
    }
}

// Get RAM usage
$memInfo = file_get_contents('/proc/meminfo');
preg_match('/MemTotal:\s+(\d+)/', $memInfo, $totalMatch);
preg_match('/MemAvailable:\s+(\d+)/', $memInfo, $availMatch);

if (!empty($totalMatch[1]) && !empty($availMatch[1])) {
    $memTotal = (int)$totalMatch[1];
    $memAvail = (int)$availMatch[1];
    $memUsed = $memTotal - $memAvail;
    $ramUsage = ($memUsed / $memTotal) * 100;
    
    echo "[" . date('Y-m-d H:i:s') . "] RAM Usage: " . round($ramUsage, 1) . "%\n";
    
    // Check RAM usage alert
    $result = $notifier->alertRamUsage($ramUsage);
    if ($result) {
        if ($result['success']) {
            echo "[" . date('Y-m-d H:i:s') . "] ✓ RAM usage alert sent!\n";
        } else {
            echo "[" . date('Y-m-d H:i:s') . "] ✗ Failed to send RAM alert: " . ($result['error'] ?? 'unknown') . "\n";
        }
    }
}

echo "[" . date('Y-m-d H:i:s') . "] System Monitor completed.\n";
