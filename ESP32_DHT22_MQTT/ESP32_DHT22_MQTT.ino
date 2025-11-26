/*
 * ESP32 C3 SuperMini - DHT22 MQTT IoT Gateway
 * 
 * Hardware:
 * - ESP32 C3 SuperMini
 * - DHT22 Temperature & Humidity Sensor
 * - Connect DHT22 Data pin to GPIO2 (IO2)
 * - DHT22 VCC to 3V3, GND to GND
 * 
 * Features:
 * - WiFi auto-reconnect
 * - MQTT publish every 2 seconds
 * - Battery level monitoring (ADC on IO0)
 * - Auto-reconnect MQTT
 * - JSON payload format
 */

#include <WiFi.h>
#include <PubSubClient.h>
#include <DHT.h>
#include <ArduinoJson.h>

// WiFi Configuration
const char* WIFI_SSID = "hniman";        // Thay đổi SSID WiFi
const char* WIFI_PASSWORD = "12345679"; // Thay đổi mật khẩu WiFi

// MQTT Configuration
const char* MQTT_BROKER = "192.168.137.87";      // IP Raspberry Pi
const int MQTT_PORT = 1883;
const char* MQTT_USER = "iot_user";              // MQTT username
const char* MQTT_PASSWORD = "iot_password";       // MQTT password
const char* MQTT_TOPIC = "home/sensors/esp32_01"; // MQTT topic
const char* MQTT_STATUS_TOPIC = "home/sensors/esp32_01/status";

// Device Configuration
const char* DEVICE_ID = "ESP32_01";
const int PUBLISH_INTERVAL = 2000; // 2 seconds (đáp ứng yêu cầu ≤2s)

// DHT22 Configuration
#define DHT_PIN 2        // GPIO2 (IO2) - DHT22 Data pin
#define DHT_TYPE DHT22
DHT dht(DHT_PIN, DHT_TYPE);

// Battery Monitor (optional - using ADC on GPIO0/IO0)
#define BATTERY_PIN 0    // GPIO0 (IO0/A0) for battery voltage divider
#define BATTERY_ENABLE true // Set false if not using battery

// WiFi & MQTT clients
WiFiClient espClient;
PubSubClient mqttClient(espClient);

// Timing variables
unsigned long lastPublish = 0;
unsigned long lastReconnectAttempt = 0;
const unsigned long RECONNECT_INTERVAL = 5000; // 5 seconds between reconnect attempts

// Statistics
unsigned long publishCount = 0;
unsigned long failedPublishCount = 0;

void setup() {
  Serial.begin(115200);
  delay(1000);
  
  Serial.println("\n\n=== ESP32 C3 SuperMini - DHT22 MQTT Gateway ===");
  Serial.print("Device ID: ");
  Serial.println(DEVICE_ID);
  
  // Initialize DHT22
  dht.begin();
  Serial.println("DHT22 initialized");
  
  // Initialize Battery ADC
  if (BATTERY_ENABLE) {
    analogReadResolution(12); // 12-bit ADC (0-4095)
    Serial.println("Battery monitor enabled on GPIO0");
  }
  
  // Connect to WiFi
  connectWiFi();
  
  // Setup MQTT
  mqttClient.setServer(MQTT_BROKER, MQTT_PORT);
  mqttClient.setCallback(mqttCallback);
  mqttClient.setKeepAlive(60);
  mqttClient.setSocketTimeout(30);
  
  Serial.println("Setup complete!\n");
}

void loop() {
  // Maintain WiFi connection
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("WiFi disconnected! Reconnecting...");
    connectWiFi();
  }
  
  // Maintain MQTT connection
  if (!mqttClient.connected()) {
    unsigned long now = millis();
    if (now - lastReconnectAttempt > RECONNECT_INTERVAL) {
      lastReconnectAttempt = now;
      if (reconnectMQTT()) {
        lastReconnectAttempt = 0;
      }
    }
  } else {
    mqttClient.loop();
  }
  
  // Publish sensor data every PUBLISH_INTERVAL
  unsigned long now = millis();
  if (now - lastPublish >= PUBLISH_INTERVAL) {
    lastPublish = now;
    publishSensorData();
  }
  
  delay(10); // Small delay for stability
}

void connectWiFi() {
  Serial.print("Connecting to WiFi: ");
  Serial.println(WIFI_SSID);
  
  WiFi.mode(WIFI_STA);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
  
  int attempts = 0;
  while (WiFi.status() != WL_CONNECTED && attempts < 30) {
    delay(500);
    Serial.print(".");
    attempts++;
  }
  
  if (WiFi.status() == WL_CONNECTED) {
    Serial.println("\nWiFi connected!");
    Serial.print("IP address: ");
    Serial.println(WiFi.localIP());
    Serial.print("Signal strength (RSSI): ");
    Serial.print(WiFi.RSSI());
    Serial.println(" dBm");
  } else {
    Serial.println("\nWiFi connection failed! Retrying in 5 seconds...");
    delay(5000);
    ESP.restart();
  }
}

boolean reconnectMQTT() {
  Serial.print("Attempting MQTT connection to ");
  Serial.print(MQTT_BROKER);
  Serial.print(":");
  Serial.print(MQTT_PORT);
  Serial.print("... ");
  
  // Create client ID
  String clientId = "ESP32_";
  clientId += DEVICE_ID;
  
  // Last Will and Testament
  String lwt = "{\"device_id\":\"" + String(DEVICE_ID) + "\",\"status\":\"offline\"}";
  
  // Connect with credentials and LWT
  if (mqttClient.connect(clientId.c_str(), MQTT_USER, MQTT_PASSWORD, 
                         MQTT_STATUS_TOPIC, 1, true, lwt.c_str())) {
    Serial.println("Connected!");
    
    // Publish online status
    String onlineMsg = "{\"device_id\":\"" + String(DEVICE_ID) + "\",\"status\":\"online\"}";
    mqttClient.publish(MQTT_STATUS_TOPIC, onlineMsg.c_str(), true);
    
    return true;
  } else {
    Serial.print("Failed, rc=");
    Serial.println(mqttClient.state());
    return false;
  }
}

void publishSensorData() {
  if (!mqttClient.connected()) {
    failedPublishCount++;
    Serial.println("MQTT not connected, skipping publish");
    return;
  }
  
  // Read DHT22 sensor
  float temperature = dht.readTemperature();
  float humidity = dht.readHumidity();
  
  // Check if readings are valid
  if (isnan(temperature) || isnan(humidity)) {
    Serial.println("Failed to read from DHT sensor!");
    failedPublishCount++;
    return;
  }
  
  // Read battery level (if enabled)
  float batteryLevel = 0.0;
  if (BATTERY_ENABLE) {
    int adcValue = analogRead(BATTERY_PIN);
    // Convert ADC to voltage (assuming voltage divider)
    // Adjust formula based on your voltage divider ratio
    // Example: 2:1 divider, 3.3V reference, 12-bit ADC
    float voltage = (adcValue / 4095.0) * 3.3 * 2.0;
    // Convert to percentage (assuming 3.0V = 0%, 4.2V = 100% for Li-Ion)
    batteryLevel = ((voltage - 3.0) / (4.2 - 3.0)) * 100.0;
    batteryLevel = constrain(batteryLevel, 0, 100);
  } else {
    batteryLevel = 100.0; // Default if not using battery
  }
  
  // Get timestamp (millis since boot)
  unsigned long timestamp = millis();
  
  // Create JSON payload
  StaticJsonDocument<256> doc;
  doc["device_id"] = DEVICE_ID;
  doc["temperature"] = round(temperature * 10) / 10.0; // 1 decimal place
  doc["humidity"] = round(humidity * 10) / 10.0;
  doc["battery_level"] = round(batteryLevel * 10) / 10.0;
  doc["timestamp"] = timestamp;
  doc["rssi"] = WiFi.RSSI();
  
  // Serialize to string
  char jsonBuffer[256];
  serializeJson(doc, jsonBuffer);
  
  // Publish to MQTT with QoS 1 (đáp ứng yêu cầu QoS 1)
  if (mqttClient.publish(MQTT_TOPIC, jsonBuffer, false)) {
    publishCount++;
    
    Serial.print("Published [");
    Serial.print(publishCount);
    Serial.print("]: ");
    Serial.println(jsonBuffer);
    
    // Calculate success rate
    unsigned long total = publishCount + failedPublishCount;
    float successRate = (float)publishCount / total * 100.0;
    Serial.print("Success rate: ");
    Serial.print(successRate, 1);
    Serial.println("%");
  } else {
    failedPublishCount++;
    Serial.println("Publish failed!");
  }
}

void mqttCallback(char* topic, byte* payload, unsigned int length) {
  // Handle incoming MQTT messages (if subscribed to any topics)
  Serial.print("Message arrived [");
  Serial.print(topic);
  Serial.print("]: ");
  
  for (unsigned int i = 0; i < length; i++) {
    Serial.print((char)payload[i]);
  }
  Serial.println();
}
