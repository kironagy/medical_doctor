<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$user = App\Models\User::where('role', 'admin')->first();
if (!$user) {
    echo "No admin user found\n";
    exit;
}
auth()->login($user);

$request = Illuminate\Http\Request::create('/admin/doctors', 'POST', [
    'name' => 'Dr. Test Web',
    'email' => 'testweb@example.com',
    'password' => '123456',
    'phone' => '12345678',
    'specialization' => 'Cardiology'
]);
$request->setSession(session()->driver());
$request->headers->set('X-CSRF-TOKEN', csrf_token());
// Need to bypass CSRF token mismatch, let's just use WithoutMiddleware
