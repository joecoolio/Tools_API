<?php

return [
    'settings' => [
        'displayErrorDetails' => (bool)getenv('DISPLAY_ERRORS'),

        'logger' => [
            'name' => 'slim-app',
            'level' => (int)getenv('LOG_LEVEL') ?: 400,
            'path' => __DIR__ . '/../../logs/app.log',
        ],
    ]
];
