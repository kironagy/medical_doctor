<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$request = Illuminate\Http\Request::create('/admin/doctors', 'POST', [
    'name' => 'Dr. Smith',
    'email' => 'smith@example.com',
    'password' => '123456',
    'phone' => '12345678',
    'specialization' => 'Cardiology'
]);
$response = app()->handle($request);
echo $response->getContent();
echo "Status: " . $response->getStatusCode() . "\n";
if ($response->isRedirect()) {
    echo "Redirect: " . $response->headers->get('Location') . "\n";
    echo "Session errors: " . json_encode(session()->get('errors') ? session()->get('errors')->getMessages() : null) . "\n";
}
