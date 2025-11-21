<?php
/**
 * IoT Sensors API
 * 
 * RESTful API endpoints for ESP32 sensor data
 * 
 * Endpoints:
 * - GET /api_iot.php?action=latest&device_id=ESP32_01
 * - GET /api_iot.php?action=history&device_id=ESP32_01&limit=100
 * - GET /api_iot.php?action=devices
 * - GET /api_iot.php?action=stats&device_id=ESP32_01
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

define('DB_PATH', __DIR__ . '/../data/iot_sensors.db');

class IoTAPI {
    private $db;
    
    public function __construct() {
        if (!file_exists(DB_PATH)) {
            $this->sendError('Database not found. MQTT subscriber may not be running.', 500);
        }
        
        try {
            $this->db = new PDO('sqlite:' . DB_PATH);
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->sendError('Database connection failed: ' . $e->getMessage(), 500);
        }
    }
    
    public function handleRequest() {
        $action = $_GET['action'] ?? 'latest';
        
        switch ($action) {
            case 'latest':
                $this->getLatestData();
                break;
            case 'history':
                $this->getHistory();
                break;
            case 'devices':
                $this->getDevices();
                break;
            case 'stats':
                $this->getStats();
                break;
            case 'realtime':
                $this->getRealtimeData();
                break;
            default:
                $this->sendError('Invalid action', 400);
        }
    }
    
    private function getLatestData() {
        $deviceId = $_GET['device_id'] ?? null;
        
        try {
            if ($deviceId) {
                // Get latest data for specific device
                $stmt = $this->db->prepare("
                    SELECT * FROM sensor_data 
                    WHERE device_id = :device_id 
                    ORDER BY received_at DESC 
                    LIMIT 1
                ");
                $stmt->execute([':device_id' => $deviceId]);
                $data = $stmt->fetch();
                
                if (!$data) {
                    $this->sendError('No data found for device', 404);
                }
                
                $this->sendSuccess($data);
            } else {
                // Get latest data for all devices
                $stmt = $this->db->query("
                    SELECT s.* 
                    FROM sensor_data s
                    INNER JOIN (
                        SELECT device_id, MAX(received_at) as max_time
                        FROM sensor_data
                        GROUP BY device_id
                    ) m ON s.device_id = m.device_id AND s.received_at = m.max_time
                    ORDER BY s.device_id
                ");
                $data = $stmt->fetchAll();
                
                $this->sendSuccess($data);
            }
        } catch (PDOException $e) {
            $this->sendError('Database error: ' . $e->getMessage(), 500);
        }
    }
    
    private function getHistory() {
        $deviceId = $_GET['device_id'] ?? null;
        $limit = min((int)($_GET['limit'] ?? 100), 1000); // Max 1000 records
        $offset = (int)($_GET['offset'] ?? 0);
        $since = (int)($_GET['since'] ?? 0); // Unix timestamp
        
        try {
            if ($deviceId) {
                $query = "
                    SELECT * FROM sensor_data 
                    WHERE device_id = :device_id
                ";
                
                if ($since > 0) {
                    $query .= " AND received_at >= :since";
                }
                
                $query .= " ORDER BY received_at DESC LIMIT :limit OFFSET :offset";
                
                $stmt = $this->db->prepare($query);
                $stmt->bindValue(':device_id', $deviceId);
                $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
                
                if ($since > 0) {
                    $stmt->bindValue(':since', $since, PDO::PARAM_INT);
                }
                
                $stmt->execute();
                $data = $stmt->fetchAll();
                
                $this->sendSuccess([
                    'count' => count($data),
                    'limit' => $limit,
                    'offset' => $offset,
                    'data' => $data
                ]);
            } else {
                $this->sendError('device_id is required', 400);
            }
        } catch (PDOException $e) {
            $this->sendError('Database error: ' . $e->getMessage(), 500);
        }
    }
    
    private function getDevices() {
        try {
            $stmt = $this->db->query("
                SELECT 
                    ds.device_id,
                    ds.status,
                    ds.last_seen,
                    ds.updated_at,
                    COUNT(sd.id) as total_records,
                    MAX(sd.received_at) as last_data_time,
                    AVG(sd.temperature) as avg_temperature,
                    AVG(sd.humidity) as avg_humidity,
                    AVG(sd.battery_level) as avg_battery
                FROM device_status ds
                LEFT JOIN sensor_data sd ON ds.device_id = sd.device_id
                GROUP BY ds.device_id
                ORDER BY ds.device_id
            ");
            
            $devices = $stmt->fetchAll();
            
            // Add online/offline status based on last_seen
            $currentTime = time();
            foreach ($devices as &$device) {
                $timeSinceLastSeen = $currentTime - $device['last_seen'];
                $device['is_online'] = $timeSinceLastSeen < 30; // Offline if no data for 30 seconds
                $device['time_since_last_seen'] = $timeSinceLastSeen;
            }
            
            $this->sendSuccess($devices);
        } catch (PDOException $e) {
            $this->sendError('Database error: ' . $e->getMessage(), 500);
        }
    }
    
    private function getStats() {
        $deviceId = $_GET['device_id'] ?? null;
        $period = $_GET['period'] ?? '24h'; // 1h, 24h, 7d, 30d
        
        if (!$deviceId) {
            $this->sendError('device_id is required', 400);
        }
        
        // Calculate time range
        $periodSeconds = [
            '1h' => 3600,
            '24h' => 86400,
            '7d' => 604800,
            '30d' => 2592000
        ];
        
        $seconds = $periodSeconds[$period] ?? 86400;
        $since = time() - $seconds;
        
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    COUNT(*) as total_records,
                    MIN(temperature) as min_temp,
                    MAX(temperature) as max_temp,
                    AVG(temperature) as avg_temp,
                    MIN(humidity) as min_humidity,
                    MAX(humidity) as max_humidity,
                    AVG(humidity) as avg_humidity,
                    AVG(battery_level) as avg_battery,
                    MIN(battery_level) as min_battery,
                    AVG(rssi) as avg_rssi
                FROM sensor_data
                WHERE device_id = :device_id
                AND received_at >= :since
            ");
            
            $stmt->execute([
                ':device_id' => $deviceId,
                ':since' => $since
            ]);
            
            $stats = $stmt->fetch();
            
            $this->sendSuccess([
                'device_id' => $deviceId,
                'period' => $period,
                'stats' => $stats
            ]);
        } catch (PDOException $e) {
            $this->sendError('Database error: ' . $e->getMessage(), 500);
        }
    }
    
    private function getRealtimeData() {
        // Get data from last 10 seconds for all devices
        $since = time() - 10;
        
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM sensor_data 
                WHERE received_at >= :since
                ORDER BY received_at DESC
            ");
            $stmt->execute([':since' => $since]);
            $data = $stmt->fetchAll();
            
            $this->sendSuccess([
                'timestamp' => time(),
                'count' => count($data),
                'data' => $data
            ]);
        } catch (PDOException $e) {
            $this->sendError('Database error: ' . $e->getMessage(), 500);
        }
    }
    
    private function sendSuccess($data) {
        echo json_encode([
            'success' => true,
            'data' => $data,
            'timestamp' => time()
        ]);
        exit;
    }
    
    private function sendError($message, $code = 400) {
        http_response_code($code);
        echo json_encode([
            'success' => false,
            'error' => $message,
            'timestamp' => time()
        ]);
        exit;
    }
}

// Handle request
$api = new IoTAPI();
$api->handleRequest();
