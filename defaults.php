<?php
return array (
  'thresholds' =>
  array (
    'warn_cpu_temp' => '65',
    'warn_ram_space' => '80',
    'warn_loads_size' => '2',
    'upd_time_interval' => '15',
  ),
  'general' =>
  array (
    'pass' => '63a9f0ea7bb98050796b649e85481845',
    'initialsetup' => '0',
    'tempunit' => '0'
  ),
  'telegram' =>
  array (
    'enabled' => false,
    'bot_token' => '',
    'chat_id' => '',
    'cooldown_minutes' => 5,
    'thresholds' =>
    array (
      'cpu_temp_high' => 70,
      'cpu_temp_critical' => 80,
      'ram_usage_high' => 85,
      'ram_usage_critical' => 95,
      'sensor_temp_high' => 40,
      'sensor_temp_low' => 5,
      'sensor_humidity_high' => 90,
      'sensor_humidity_low' => 20,
      'battery_low' => 20,
    ),
  ),
);
?>
