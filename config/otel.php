<?php

return [
    'endpoint' => env('OTEL_EXPORTER_OTLP_ENDPOINT', ''),
    'service_name' => env('OTEL_SERVICE_NAME', 'iicp-directory'),
];
