<?php

return [
    'api_url' => rtrim(env('MOBILE_API_URL', env('API_URL', 'https://prof-hosam-fekry.online/api')), '/'),
    'timeout' => env('MOBILE_API_TIMEOUT', 20),
];
