<?php

return [
    'online_threshold_seconds' => (int) env('PRESENCE_ONLINE_THRESHOLD_SECONDS', 120),
    'heartbeat_interval_seconds' => (int) env('PRESENCE_HEARTBEAT_INTERVAL_SECONDS', 30),
];
