/**
 * IoT Sensors Real-time Dashboard Module
 * Handles ESP32 sensor data visualization
 */

class IoTDashboard {
    constructor() {
        this.historyChartData = {
            temperature: [],
            humidity: []
        };
        this.maxDataPoints = 30;
        this.devices = [];
        this.charts = {};
    }
    
    init() {
        console.log('IoT Dashboard initializing...');
        this.loadDevices();
    }
    
    refreshDevice(deviceId) {
        fetch(`backend/api_iot.php?action=latest&device_id=${deviceId}`)
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    this.updateDeviceCard(result.data);
                    
                    // Update chart if visible
                    const chartContainer = document.getElementById(`chart-container-${deviceId}`);
                    if (chartContainer && chartContainer.style.display !== 'none') {
                        this.loadChartHistory(deviceId);
                    }
                }
            })
            .catch(error => {
                console.error('Error refreshing device:', error);
            });
    }
    
    loadDevices() {
        fetch('backend/api_iot.php?action=devices')
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    this.devices = result.data;
                    this.renderDeviceCards();
                    console.log('Loaded devices:', this.devices);
                } else {
                    console.error('Failed to load devices:', result.error);
                }
            })
            .catch(error => {
                console.error('Error loading devices:', error);
                this.showError('Không thể kết nối đến MQTT subscriber. Vui lòng kiểm tra service đang chạy.');
            });
    }
    
    updateAllDevices() {
        fetch('backend/api_iot.php?action=latest')
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    const data = result.data;
                    if (Array.isArray(data)) {
                        data.forEach(deviceData => {
                            this.updateDeviceCard(deviceData);
                            this.updateChart(deviceData);
                        });
                    } else {
                        this.updateDeviceCard(data);
                        this.updateChart(data);
                    }
                }
            })
            .catch(error => {
                console.error('Error updating devices:', error);
            });
    }
    
    renderDeviceCards() {
        const container = document.getElementById('iot-sensors-container');
        if (!container) return;
        
        container.innerHTML = '';
        
        if (this.devices.length === 0) {
            container.innerHTML = `
                <div class="col-12">
                    <div class="alert alert-info" role="alert">
                        <i class="bi bi-info-circle"></i> Chưa có thiết bị ESP32 nào kết nối. 
                        Vui lòng khởi động ESP32 và đảm bảo MQTT subscriber đang chạy.
                    </div>
                </div>
            `;
            return;
        }
        
        this.devices.forEach(device => {
            const cardHtml = this.createDeviceCard(device);
            container.innerHTML += cardHtml;
        });
        
        // Initialize charts after cards are rendered
        setTimeout(() => {
            this.devices.forEach(device => {
                // Don't initialize charts by default to reduce lag
                // Charts will be initialized when user clicks "Show Chart"
            });
            
            // Load initial data for all devices
            this.updateAllDevices();
        }, 100);
    }
    
    createDeviceCard(device) {
        const isOnline = device.is_online;
        const statusBadge = isOnline 
            ? '<span class="badge bg-success">Online</span>' 
            : '<span class="badge bg-danger">Offline</span>';
        
        return `
            <div class="col-12 col-md-6 pt-3">
                <div class="card shadow-sm">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="bi bi-thermometer-half"></i> ${device.device_id}
                        </h5>
                        <div>
                            ${statusBadge}
                            <button class="btn btn-sm btn-outline-primary ms-2" onclick="iotDashboard.refreshDevice('${device.device_id}')">
                                <i class="bi bi-arrow-clockwise"></i> Reload
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-6 text-center">
                                <h6 class="text-muted">Nhiệt độ</h6>
                                <h3 class="text-danger">
                                    <i class="bi bi-thermometer-sun"></i>
                                    <span id="temp-${device.device_id}">--</span>°C
                                </h3>
                            </div>
                            <div class="col-6 text-center">
                                <h6 class="text-muted">Độ ẩm</h6>
                                <h3 class="text-primary">
                                    <i class="bi bi-droplet-half"></i>
                                    <span id="humid-${device.device_id}">--</span>%
                                </h3>
                            </div>
                        </div>
                        <div class="row mt-3">
                            <div class="col-6">
                                <small class="text-muted">
                                    <i class="bi bi-battery-charging"></i> Pin: 
                                    <span id="battery-${device.device_id}">--</span>%
                                </small>
                            </div>
                            <div class="col-6">
                                <small class="text-muted">
                                    <i class="bi bi-reception-4"></i> RSSI: 
                                    <span id="rssi-${device.device_id}">--</span> dBm
                                </small>
                            </div>
                        </div>
                        <div class="mt-3" id="chart-container-${device.device_id}" style="display:none;">
                            <canvas id="chart-${device.device_id}" height="80"></canvas>
                        </div>
                        <div class="text-center mt-2">
                            <button class="btn btn-sm btn-outline-secondary" onclick="iotDashboard.toggleChart('${device.device_id}')">
                                <i class="bi bi-graph-up"></i> <span id="chart-toggle-${device.device_id}">Show Chart</span>
                            </button>
                        </div>
                        <p class="card-text mt-2">
                            <small class="text-muted">
                                Cập nhật: <span id="lastupdate-${device.device_id}">--</span>
                            </small>
                        </p>
                    </div>
                </div>
            </div>
        `;
    }
    
    updateDeviceCard(data) {
        if (!data || !data.device_id) return;
        
        const deviceId = data.device_id;
        
        // Update temperature
        const tempEl = document.getElementById(`temp-${deviceId}`);
        if (tempEl) {
            tempEl.textContent = data.temperature ? data.temperature.toFixed(1) : '--';
            
            // Color coding based on temperature
            const temp = data.temperature;
            if (temp > 30) {
                tempEl.parentElement.className = 'text-danger';
            } else if (temp < 20) {
                tempEl.parentElement.className = 'text-info';
            } else {
                tempEl.parentElement.className = 'text-warning';
            }
        }
        
        // Update humidity
        const humidEl = document.getElementById(`humid-${deviceId}`);
        if (humidEl) {
            humidEl.textContent = data.humidity ? data.humidity.toFixed(1) : '--';
        }
        
        // Update battery
        const batteryEl = document.getElementById(`battery-${deviceId}`);
        if (batteryEl) {
            const battery = data.battery_level ? data.battery_level.toFixed(0) : '--';
            batteryEl.textContent = battery;
            
            // Change battery icon based on level
            const batteryIcon = batteryEl.previousElementSibling;
            if (data.battery_level) {
                if (data.battery_level > 75) {
                    batteryIcon.className = 'bi bi-battery-full text-success';
                } else if (data.battery_level > 50) {
                    batteryIcon.className = 'bi bi-battery-half text-warning';
                } else if (data.battery_level > 25) {
                    batteryIcon.className = 'bi bi-battery-half text-warning';
                } else {
                    batteryIcon.className = 'bi bi-battery text-danger';
                }
            }
        }
        
        // Update RSSI
        const rssiEl = document.getElementById(`rssi-${deviceId}`);
        if (rssiEl) {
            rssiEl.textContent = data.rssi ? data.rssi : '--';
        }
        
        // Update timestamp
        const lastUpdateEl = document.getElementById(`lastupdate-${deviceId}`);
        if (lastUpdateEl) {
            const now = new Date();
            lastUpdateEl.textContent = now.toLocaleTimeString('vi-VN');
        }
    }
    
    initChart(deviceId) {
        const canvas = document.getElementById(`chart-${deviceId}`);
        if (!canvas) return;
        
        const ctx = canvas.getContext('2d');
        
        // Chart.js 2.9.3 syntax
        this.charts[deviceId] = new Chart(ctx, {
            type: 'line',
            data: {
                labels: [],
                datasets: [
                    {
                        label: 'Nhiệt độ (°C)',
                        data: [],
                        borderColor: 'rgb(255, 99, 132)',
                        backgroundColor: 'rgba(255, 99, 132, 0.1)',
                        borderWidth: 2,
                        lineTension: 0.4,
                        yAxisID: 'y-axis-1'
                    },
                    {
                        label: 'Độ ẩm (%)',
                        data: [],
                        borderColor: 'rgb(54, 162, 235)',
                        backgroundColor: 'rgba(54, 162, 235, 0.1)',
                        borderWidth: 2,
                        lineTension: 0.4,
                        yAxisID: 'y-axis-2'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                tooltips: {
                    mode: 'index',
                    intersect: false,
                },
                legend: {
                    display: true,
                    position: 'bottom',
                    labels: {
                        boxWidth: 12,
                        fontSize: 10
                    }
                },
                scales: {
                    yAxes: [{
                        id: 'y-axis-1',
                        type: 'linear',
                        display: true,
                        position: 'left',
                        scaleLabel: {
                            display: true,
                            labelString: '°C',
                            fontSize: 10
                        },
                        ticks: {
                            fontSize: 9,
                            stepSize: 0.5
                        }
                    }, {
                        id: 'y-axis-2',
                        type: 'linear',
                        display: true,
                        position: 'right',
                        scaleLabel: {
                            display: true,
                            labelString: '%',
                            fontSize: 10
                        },
                        gridLines: {
                            drawOnChartArea: false,
                        },
                        ticks: {
                            fontSize: 9,
                            stepSize: 0.5
                        }
                    }],
                    xAxes: [{
                        ticks: {
                            fontSize: 9,
                            maxRotation: 0,
                            autoSkip: true,
                            maxTicksLimit: 6
                        }
                    }]
                }
            }
        });
    }
    
    refreshDevice(deviceId) {
        console.log('Refreshing device:', deviceId);
        
        // Show loading state
        const reloadBtn = event?.target?.closest('button');
        if (reloadBtn) {
            const originalHTML = reloadBtn.innerHTML;
            reloadBtn.innerHTML = '<i class="bi bi-arrow-clockwise"></i> ...';
            reloadBtn.disabled = true;
        }
        
        fetch(`backend/api_iot.php?action=latest&device_id=${deviceId}`)
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    this.updateDeviceCard(result.data);
                    
                    // Update chart if visible
                    const chartContainer = document.getElementById(`chart-container-${deviceId}`);
                    if (chartContainer && chartContainer.style.display !== 'none') {
                        // Reload chart history instead of just adding one point
                        this.loadChartHistory(deviceId);
                    }
                } else {
                    console.error('Failed to refresh device:', result.error);
                }
            })
            .catch(error => {
                console.error('Error refreshing device:', error);
            })
            .finally(() => {
                if (reloadBtn) {
                    reloadBtn.innerHTML = '<i class="bi bi-arrow-clockwise"></i> Reload';
                    reloadBtn.disabled = false;
                }
            });
    }
    
    toggleChart(deviceId) {
        const chartContainer = document.getElementById(`chart-container-${deviceId}`);
        const toggleText = document.getElementById(`chart-toggle-${deviceId}`);
        
        if (chartContainer.style.display === 'none') {
            chartContainer.style.display = 'block';
            toggleText.textContent = 'Hide Chart';
            
            // Initialize chart if not exists
            if (!this.charts[deviceId]) {
                this.initChart(deviceId);
            }
            
            // Load historical data for chart
            this.loadChartHistory(deviceId);
        } else {
            chartContainer.style.display = 'none';
            toggleText.textContent = 'Show Chart';
        }
    }
    
    loadChartHistory(deviceId) {
        console.log('Loading chart history for:', deviceId);
        // Add timestamp to prevent browser caching
        const cacheBuster = Date.now();
        fetch(`backend/api_iot.php?action=history&device_id=${deviceId}&limit=${this.maxDataPoints}&_=${cacheBuster}`)
            .then(response => response.json())
            .then(result => {
                if (result.success && result.data.data && result.data.data.length > 0) {
                    const chart = this.charts[deviceId];
                    if (!chart) return;
                    
                    // Clear existing data
                    chart.data.labels = [];
                    chart.data.datasets[0].data = [];
                    chart.data.datasets[1].data = [];
                    
                    // Data comes newest first, reverse to show oldest first
                    const historyData = [...result.data.data].reverse();
                    
                    historyData.forEach(item => {
                        const time = new Date(item.received_at * 1000);
                        const timeLabel = time.toLocaleTimeString('vi-VN', { 
                            hour: '2-digit', 
                            minute: '2-digit'
                        });
                        
                        chart.data.labels.push(timeLabel);
                        chart.data.datasets[0].data.push(parseFloat(item.temperature));
                        chart.data.datasets[1].data.push(parseFloat(item.humidity));
                    });
                    
                    chart.update();
                    console.log('Chart updated with', historyData.length, 'historical points');
                }
            })
            .catch(error => {
                console.error('Error loading chart history:', error);
            });
    }
    
    updateChart(data) {
        if (!data || !data.device_id) return;
        
        const deviceId = data.device_id;
        const chart = this.charts[deviceId];
        
        if (!chart) return;
        
        const now = new Date();
        const timeLabel = now.toLocaleTimeString('vi-VN', { 
            hour: '2-digit', 
            minute: '2-digit', 
            second: '2-digit' 
        });
        
        // Add new data
        chart.data.labels.push(timeLabel);
        chart.data.datasets[0].data.push(data.temperature);
        chart.data.datasets[1].data.push(data.humidity);
        
        // Keep only last maxDataPoints
        if (chart.data.labels.length > this.maxDataPoints) {
            chart.data.labels.shift();
            chart.data.datasets[0].data.shift();
            chart.data.datasets[1].data.shift();
        }
        
        chart.update('none'); // Update without animation for better performance
    }
    
    showError(message) {
        const container = document.getElementById('iot-sensors-container');
        if (container) {
            container.innerHTML = `
                <div class="col-12">
                    <div class="alert alert-danger" role="alert">
                        <i class="bi bi-exclamation-triangle"></i> ${message}
                    </div>
                </div>
            `;
        }
    }
}

// Initialize IoT Dashboard when DOM is ready
let iotDashboard;

document.addEventListener('DOMContentLoaded', function() {
    // Check if IoT container exists
    if (document.getElementById('iot-sensors-container')) {
        iotDashboard = new IoTDashboard();
        iotDashboard.init();
        console.log('IoT Dashboard initialized');
    }
});
