# Docker Deployment Guide - RaspberryDashboard

## 🐳 Triển Khai với Docker

### Yêu Cầu
- Docker Engine 20.10+
- Docker Compose 2.0+

### Cách 1: Sử dụng Docker Compose (Khuyến nghị)

```bash
# Clone repository
git clone https://github.com/hniman135/RaspberryDashboard.git
cd RaspberryDashboard

# Build và chạy
docker-compose up -d

# Xem logs
docker-compose logs -f

# Dừng services
docker-compose down
```

### Cách 2: Build Image thủ công

```bash
# Build image
docker build -t rpi-dashboard:latest .

# Chạy container
docker run -d \
  --name rpi-dashboard \
  -p 8080:80 \
  -p 1883:1883 \
  -v rpi_data:/var/www/html/data \
  -e TZ=Asia/Ho_Chi_Minh \
  rpi-dashboard:latest
```

### Truy cập Dashboard

- **Web Dashboard**: http://localhost:8080
- **MQTT Broker**: localhost:1883

### Cấu hình ESP32

Cập nhật firmware ESP32 để kết nối tới Docker container:

```cpp
// Thay đổi địa chỉ MQTT Broker
const char* mqtt_server = "192.168.x.x";  // IP của máy chạy Docker
const int mqtt_port = 1883;
```

### Volumes & Persistence

| Volume | Mô tả |
|--------|-------|
| `dashboard_data` | Database SQLite và logs |
| `mosquitto_data` | MQTT persistence data |
| `mosquitto_logs` | MQTT logs |

### Biến Môi Trường

| Variable | Mặc định | Mô tả |
|----------|----------|-------|
| `TZ` | Asia/Ho_Chi_Minh | Múi giờ |
| `MQTT_BROKER` | 127.0.0.1 | Địa chỉ MQTT |
| `MQTT_PORT` | 1883 | Port MQTT |
| `MQTT_USER` | iot_user | Username MQTT |
| `MQTT_PASSWORD` | iot_password | Password MQTT |

### Troubleshooting

```bash
# Xem logs của container
docker logs rpi-dashboard

# Vào shell container
docker exec -it rpi-dashboard bash

# Kiểm tra MQTT
docker exec -it rpi-dashboard mosquitto_sub -t "home/sensors/#" -v

# Restart services
docker-compose restart
```

### Multi-Architecture (ARM/x86)

Để build cho Raspberry Pi (ARM):

```bash
# Trên máy có Docker Buildx
docker buildx build --platform linux/arm64,linux/amd64 -t rpi-dashboard:latest .
```
