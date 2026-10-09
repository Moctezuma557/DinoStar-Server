<?php

return [
    'host' => env('MQTT_HOST', 'broker'),
    'port' => (int) env('MQTT_PORT', 1883),
    'topic' => env('MQTT_TOPIC', 'hospital/braquio/telemetria'),
];
