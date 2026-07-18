<?php
$token = 'dummy'; // We need the real token, or we can just login.
$response = \App\Services\Mobile\ApiService::loginToRemote('doctor@medical.test', 'password');
// Actually, let's just grep the local DB for user email and pass if we have one.
