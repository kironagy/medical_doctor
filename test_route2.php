<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$user = App\Models\User::where('role', 'admin')->first();
if (!$user) {
    echo "No admin. Creating one...\n";
    $user = App\Models\User::create([
        'name' => 'Admin', 'email' => 'admin@admin.com', 'password' => bcrypt('password'), 'role' => 'admin'
    ]);
}
auth()->login($user);

$request = Illuminate\Http\Request::create('/admin/doctors', 'POST', [
    'name' => 'Dr. Test Web',
    'email' => 'testweb_'.rand().'@example.com',
    'password' => '123456',
    'phone' => '12345678',
    'specialization' => 'Cardiology'
]);
$request->setSession(app('session')->driver());
$app->make(Illuminate\Contracts\Http\Kernel::class);

$response = app()->handle($request);
echo "Status: " . $response->getStatusCode() . "\n";
if ($response->isRedirect()) {
    echo "Session errors: " . json_encode(session()->get('errors') ? session()->get('errors')->getMessages() : null) . "\n";
}
