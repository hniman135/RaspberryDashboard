# Cấu hình IoT Gateway - ESP32 + DHT22 + MQTT

## 📋 Tổng quan
Hệ thống IoT Gateway cục bộ với Raspberry Pi nhận dữ liệu từ ESP32 C3 SuperMini + DHT22 qua MQTT.

## 🔧 Yêu cầu phần cứng

### Raspberry Pi
- Raspberry Pi 3/4/5
- Hệ điều hành: Raspberry Pi OS (Debian-based)
- Kết nối mạng: WiFi hoặc Ethernet
- IP tĩnh khuyến nghị: 192.168.137.87

### ESP32 C3 SuperMini
- Board: ESP32 C3 SuperMini
- Cảm biến: DHT22 (Temperature & Humidity)
- Kết nối DHT22:
  - DHT22 VCC → ESP32 3V3
  - DHT22 GND → ESP32 GND
  - DHT22 DATA → ESP32 GPIO2 (IO2)
  - Pull-up resistor 10kΩ giữa DATA và VCC (optional, DHT22 thường có sẵn)

### Kết nối Battery Monitor (Tùy chọn)
- GPIO0 (IO0/A0) → Voltage divider từ battery
- Voltage divider ratio: 2:1 (cho battery 3.0-4.2V)

## 📦 Cài đặt trên Raspberry Pi

### Bước 1: Cài đặt MQTT Broker (Mosquitto)

```bash
# Cập nhật package list
sudo apt update
sudo apt upgrade -y

# Cài đặt Mosquitto broker và client
sudo apt install -y mosquitto mosquitto-clients

# Kích hoạt và khởi động service
sudo systemctl enable mosquitto
sudo systemctl start mosquitto

# Kiểm tra trạng thái
sudo systemctl status mosquitto
```

### Bước 2: Cấu hình Mosquitto

```bash
# Tạo file cấu hình
sudo nano /etc/mosquitto/conf.d/iot_gateway.conf
```

Thêm nội dung sau:

```
# IoT Gateway MQTT Configuration
listener 1883
allow_anonymous false
password_file /etc/mosquitto/passwd
```

### Bước 3: Tạo user authentication cho MQTT

```bash
# Tạo user 'iot_user' với password 'iot_password'
sudo mosquitto_passwd -c /etc/mosquitto/passwd iot_user
# Nhập password khi được yêu cầu: iot_password

# Restart Mosquitto để áp dụng cấu hình
sudo systemctl restart mosquitto
```

### Bước 4: Test MQTT broker

Terminal 1 (Subscribe):
```bash
mosquitto_sub -h localhost -u iot_user -P iot_password -t test/topic -v
```

Terminal 2 (Publish):
```bash
mosquitto_pub -h localhost -u iot_user -P iot_password -t test/topic -m "Hello MQTT"
```

Nếu thấy message ở Terminal 1, MQTT hoạt động tốt!

### Bước 5: Cài đặt PHP dependencies

```bash
# Cài đặt PHP và SQLite
sudo apt install -y php php-sqlite3 php-cli

# Kiểm tra PHP
php -v
```

### Bước 6: Copy project files

```bash
# Copy toàn bộ project đã được cập nhật
cd /var/www/html/
# (Files đã có sẵn từ git clone trước đó)

# Tạo thư mục data cho database
sudo mkdir -p /var/www/html/RaspberryDashboard/data
sudo chown -R www-data:www-data /var/www/html/RaspberryDashboard/data
sudo chmod -R 775 /var/www/html/RaspberryDashboard/data
```

### Bước 7: Cấu hình MQTT Subscriber Service

Tạo systemd service để chạy MQTT subscriber tự động:

```bash
sudo nano /etc/systemd/system/mqtt-subscriber.service
```

Thêm nội dung:

```ini
[Unit]
Description=MQTT Subscriber for IoT Gateway
After=network.target mosquitto.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/html/RaspberryDashboard/backend
ExecStart=/usr/bin/php /var/www/html/RaspberryDashboard/backend/mqtt_subscriber.php
Restart=always
RestartSec=10
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
```

Kích hoạt service:

```bash
# Reload systemd
sudo systemctl daemon-reload

# Kích hoạt service
sudo systemctl enable mqtt-subscriber

# Khởi động service
sudo systemctl start mqtt-subscriber

# Kiểm tra trạng thái
sudo systemctl status mqtt-subscriber

# Xem logs
sudo journalctl -u mqtt-subscriber -f
```

## 🔌 Cài đặt ESP32

### Bước 1: Cài đặt Arduino IDE

1. Download Arduino IDE từ https://www.arduino.cc/en/software
2. Cài đặt ESP32 board support:
   - Mở Arduino IDE
   - File → Preferences
   - Thêm URL vào "Additional Board Manager URLs":
     ```
     https://raw.githubusercontent.com/espressif/arduino-esp32/gh-pages/package_esp32_index.json
     ```
   - Tools → Board → Boards Manager
   - Tìm "esp32" và cài đặt "esp32 by Espressif Systems"

### Bước 2: Cài đặt thư viện

Vào Tools → Manage Libraries, tìm và cài đặt:
- **DHT sensor library** by Adafruit
- **Adafruit Unified Sensor** by Adafruit
- **PubSubClient** by Nick O'Leary
- **ArduinoJson** by Benoit Blanchon

### Bước 3: Cấu hình code ESP32

Mở file `ESP32/ESP32_DHT22_MQTT.ino` và chỉnh sửa:

```cpp
// WiFi Configuration
const char* WIFI_SSID = "TenWiFiCuaBan";        // Thay bằng SSID WiFi
const char* WIFI_PASSWORD = "MatKhauWiFi";      // Thay bằng password WiFi

// MQTT Configuration
const char* MQTT_BROKER = "192.168.137.87";     // IP của Raspberry Pi
const char* MQTT_USER = "iot_user";             // Username MQTT
const char* MQTT_PASSWORD = "iot_password";     // Password MQTT

// Device ID (nếu có nhiều ESP32, đổi tên khác nhau)
const char* DEVICE_ID = "ESP32_01";
```

### Bước 4: Upload code

1. Kết nối ESP32 C3 qua USB
2. Chọn board: Tools → Board → ESP32 Arduino → **ESP32C3 Dev Module**
3. Chọn port: Tools → Port → (chọn COM port của ESP32)
4. Upload: Sketch → Upload

### Bước 5: Kiểm tra Serial Monitor

Mở Serial Monitor (Tools → Serial Monitor, baud rate: 115200) để xem:
- Kết nối WiFi
- Kết nối MQTT
- Dữ liệu đang publish

## 🧪 Kiểm tra hệ thống

### Test 1: Kiểm tra MQTT messages trên RPi

```bash
mosquitto_sub -h localhost -u iot_user -P iot_password -t "home/sensors/#" -v
```

Bạn sẽ thấy JSON messages như:
```json
{"device_id":"ESP32_01","temperature":25.3,"humidity":65.2,"battery_level":98.5,"timestamp":12345,"rssi":-45}
```

### Test 2: Kiểm tra database

```bash
sqlite3 /var/www/html/RaspberryDashboard/data/iot_sensors.db "SELECT * FROM sensor_data ORDER BY id DESC LIMIT 5;"
```

### Test 3: Kiểm tra API

```bash
curl "http://192.168.137.87/RaspberryDashboard/backend/api_iot.php?action=latest"
```

### Test 4: Mở Dashboard

Truy cập: http://192.168.137.87/RaspberryDashboard/

Đăng nhập và xem phần "Cảm Biến IoT (ESP32 + DHT22)"

## 📊 Tiêu chí đạt được

✅ **Tốc độ thu thập**: ≤ 2s (ESP32 publish mỗi 2s)  
✅ **Độ trễ MQTT**: ≤ 1s (QoS 1, local broker)  
✅ **Độ trễ WiFi → RPi**: ≤ 500ms (local network)  
✅ **Auto-reconnect**: WiFi và MQTT tự động kết nối lại  
✅ **Dữ liệu**: `{device_id, temperature, humidity, battery_level, rssi, timestamp}`  
✅ **Dashboard**: Realtime charts, cập nhật mỗi 2s  
✅ **Storage**: SQLite database với automatic cleanup  

## 🔍 Troubleshooting

### MQTT Subscriber không chạy

```bash
# Kiểm tra logs
sudo journalctl -u mqtt-subscriber -n 50

# Restart service
sudo systemctl restart mqtt-subscriber
```

### ESP32 không kết nối WiFi

- Kiểm tra SSID và password
- Đảm bảo ESP32 ở gần router WiFi
- Kiểm tra Serial Monitor để xem lỗi

### Không thấy dữ liệu trên dashboard

1. Kiểm tra MQTT subscriber đang chạy: `sudo systemctl status mqtt-subscriber`
2. Kiểm tra database có dữ liệu: `ls -lh /var/www/html/RaspberryDashboard/data/`
3. Kiểm tra API: `curl "http://localhost/RaspberryDashboard/backend/api_iot.php?action=devices"`
4. Mở Browser Console (F12) để xem JavaScript errors

### DHT22 đọc NaN

- Kiểm tra kết nối DHT22
- Thêm pull-up resistor 10kΩ
- Đợi 2-3 giây sau khi ESP32 khởi động

## 📝 File quan trọng

```
RaspberryDashboard/
├── ESP32/
│   └── ESP32_DHT22_MQTT.ino       # Firmware ESP32
├── backend/
│   ├── mqtt_subscriber.php        # MQTT subscriber service
│   └── api_iot.php                # REST API cho IoT data
├── js/
│   └── iot_dashboard.js           # Frontend realtime updates
├── data/
│   ├── iot_sensors.db             # SQLite database
│   └── mqtt_subscriber.log        # Service logs
└── INSTALLATION.md                # File này
```

## 🚀 Mở rộng

### Thêm ESP32 device mới

1. Clone và sửa `DEVICE_ID` trong code ESP32
2. Upload lên ESP32 mới
3. Dashboard sẽ tự động phát hiện device mới

### Thay đổi publish interval

Sửa trong `ESP32_DHT22_MQTT.ino`:
```cpp
const int PUBLISH_INTERVAL = 2000; // milliseconds
```

### Backup database

```bash
cp /var/www/html/RaspberryDashboard/data/iot_sensors.db ~/iot_backup_$(date +%Y%m%d).db
```

## 📧 Liên hệ

Nếu có vấn đề, kiểm tra logs và documentation.
