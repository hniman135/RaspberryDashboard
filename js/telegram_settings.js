/**
 * Telegram Settings Module
 * Quản lý cấu hình và test Telegram notifications
 */

class TelegramSettings {
    constructor() {
        this.apiUrl = 'backend/api_telegram.php';
        this.init();
    }
    
    init() {
        this.bindEvents();
        this.loadConfig();
        this.updateFieldsVisibility();
    }
    
    bindEvents() {
        // Toggle fields visibility
        document.getElementById('telegram_enabled')?.addEventListener('change', () => {
            this.updateFieldsVisibility();
        });
        
        // Save button
        document.getElementById('telegramSaveBtn')?.addEventListener('click', () => {
            this.saveConfig();
        });
        
        // Test button
        document.getElementById('telegramTestBtn')?.addEventListener('click', () => {
            this.sendTest();
        });
    }
    
    updateFieldsVisibility() {
        const enabled = document.getElementById('telegram_enabled')?.checked;
        const fields = document.getElementById('telegramConfigFields');
        if (fields) {
            fields.style.display = enabled ? 'block' : 'none';
        }
    }
    
    async loadConfig() {
        try {
            const response = await fetch(`${this.apiUrl}?action=get_config`);
            const result = await response.json();
            
            if (result.success && result.config) {
                const config = result.config;
                
                // Set form values
                document.getElementById('telegram_enabled').checked = config.enabled;
                document.getElementById('telegram_chat_id').value = config.chat_id || '';
                document.getElementById('telegram_cooldown').value = config.cooldown_minutes || 5;
                
                // Token is masked, don't overwrite if user has input
                if (config.bot_token_masked && !document.getElementById('telegram_bot_token').value) {
                    document.getElementById('telegram_bot_token').placeholder = config.bot_token_masked || 'Not configured';
                }
                
                // Set thresholds
                if (config.thresholds) {
                    const t = config.thresholds;
                    document.getElementById('tg_cpu_temp_high').value = t.cpu_temp_high || 70;
                    document.getElementById('tg_cpu_temp_critical').value = t.cpu_temp_critical || 80;
                    document.getElementById('tg_ram_usage_high').value = t.ram_usage_high || 85;
                    document.getElementById('tg_ram_usage_critical').value = t.ram_usage_critical || 95;
                    document.getElementById('tg_sensor_temp_high').value = t.sensor_temp_high || 40;
                    document.getElementById('tg_sensor_temp_low').value = t.sensor_temp_low || 5;
                    document.getElementById('tg_sensor_humidity_high').value = t.sensor_humidity_high || 90;
                    document.getElementById('tg_sensor_humidity_low').value = t.sensor_humidity_low || 20;
                    document.getElementById('tg_battery_low').value = t.battery_low || 20;
                }
                
                this.updateFieldsVisibility();
                this.loadStatus();
            }
        } catch (error) {
            console.error('Failed to load Telegram config:', error);
        }
    }
    
    async loadStatus() {
        try {
            const response = await fetch(`${this.apiUrl}?action=status`);
            const result = await response.json();
            
            const statusDiv = document.getElementById('telegramStatus');
            if (!statusDiv) return;
            
            if (result.success) {
                if (result.configured) {
                    let botInfo = '';
                    if (result.bot_username) {
                        botInfo = ` - @${result.bot_username}`;
                    }
                    statusDiv.innerHTML = `<span class="badge bg-success"><i class="bi bi-check-circle"></i> Connected${botInfo}</span>`;
                } else if (result.enabled) {
                    statusDiv.innerHTML = `<span class="badge bg-warning"><i class="bi bi-exclamation-triangle"></i> Incomplete configuration</span>`;
                } else {
                    statusDiv.innerHTML = `<span class="badge bg-secondary"><i class="bi bi-dash-circle"></i> Disabled</span>`;
                }
            }
        } catch (error) {
            console.error('Failed to load Telegram status:', error);
        }
    }
    
    async saveConfig() {
        const feedbackDiv = document.getElementById('telegramFeedback');
        const saveBtn = document.getElementById('telegramSaveBtn');
        
        // Disable button during save
        saveBtn.disabled = true;
        saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Saving...';
        
        try {
            const config = {
                enabled: document.getElementById('telegram_enabled').checked,
                bot_token: document.getElementById('telegram_bot_token').value,
                chat_id: document.getElementById('telegram_chat_id').value,
                cooldown_minutes: parseInt(document.getElementById('telegram_cooldown').value) || 5,
                thresholds: {
                    cpu_temp_high: parseFloat(document.getElementById('tg_cpu_temp_high').value) || 70,
                    cpu_temp_critical: parseFloat(document.getElementById('tg_cpu_temp_critical').value) || 80,
                    ram_usage_high: parseFloat(document.getElementById('tg_ram_usage_high').value) || 85,
                    ram_usage_critical: parseFloat(document.getElementById('tg_ram_usage_critical').value) || 95,
                    sensor_temp_high: parseFloat(document.getElementById('tg_sensor_temp_high').value) || 40,
                    sensor_temp_low: parseFloat(document.getElementById('tg_sensor_temp_low').value) || 5,
                    sensor_humidity_high: parseFloat(document.getElementById('tg_sensor_humidity_high').value) || 90,
                    sensor_humidity_low: parseFloat(document.getElementById('tg_sensor_humidity_low').value) || 20,
                    battery_low: parseFloat(document.getElementById('tg_battery_low').value) || 20,
                }
            };
            
            const response = await fetch(`${this.apiUrl}?action=save_config`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(config)
            });
            
            const result = await response.json();
            
            if (result.success) {
                feedbackDiv.innerHTML = '<div class="alert alert-success py-1"><i class="bi bi-check-circle"></i> Configuration saved!</div>';
                this.loadStatus();
                
                // Clear message after 3 seconds
                setTimeout(() => {
                    feedbackDiv.innerHTML = '';
                }, 3000);
            } else {
                throw new Error(result.error || 'Failed to save');
            }
        } catch (error) {
            feedbackDiv.innerHTML = `<div class="alert alert-danger py-1"><i class="bi bi-x-circle"></i> ${error.message}</div>`;
        } finally {
            saveBtn.disabled = false;
            saveBtn.innerHTML = '<i class="bi bi-save"></i> Save';
        }
    }
    
    async sendTest() {
        const feedbackDiv = document.getElementById('telegramFeedback');
        const testBtn = document.getElementById('telegramTestBtn');
        
        // Check if config is saved first
        const botToken = document.getElementById('telegram_bot_token').value;
        const chatId = document.getElementById('telegram_chat_id').value;
        
        if (!botToken && !chatId) {
            feedbackDiv.innerHTML = '<div class="alert alert-warning py-1"><i class="bi bi-exclamation-triangle"></i> Please save configuration first!</div>';
            return;
        }
        
        // Disable button during test
        testBtn.disabled = true;
        testBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Sending...';
        
        try {
            const response = await fetch(`${this.apiUrl}?action=test`, {
                method: 'POST'
            });
            
            const result = await response.json();
            
            if (result.success) {
                feedbackDiv.innerHTML = '<div class="alert alert-success py-1"><i class="bi bi-check-circle"></i> Test message sent! Check your Telegram.</div>';
                
                // Clear message after 5 seconds
                setTimeout(() => {
                    feedbackDiv.innerHTML = '';
                }, 5000);
            } else {
                throw new Error(result.error || 'Failed to send test message');
            }
        } catch (error) {
            feedbackDiv.innerHTML = `<div class="alert alert-danger py-1"><i class="bi bi-x-circle"></i> ${error.message}</div>`;
        } finally {
            testBtn.disabled = false;
            testBtn.innerHTML = '<i class="bi bi-send"></i> Test';
        }
    }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    window.telegramSettings = new TelegramSettings();
});
