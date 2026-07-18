<?php
require 'vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Let's grab the first doctor
$doctor = \App\Domains\Users\Models\User::where('role', 'doctor')->first();
if ($doctor) {
    echo "Testing API with {$doctor->email}\n";
    $response = Illuminate\Support\Facades\Http::post('https://prof-hosam-fekry.online/api/v1/mobile/auth/login', [
        'email' => $doctor->email,
        'password' => 'password', // or whatever
        'device_name' => 'test'
    ]);
    echo $response->status() . "\n";
    echo $response->body() . "\n";
} else {
    echo "No doctor found\n";
}
