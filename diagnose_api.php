<?php
/** Diagnose patient visibility - production API */
require 'vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Domains\Users\Models\User;
use App\Domains\Patients\Models\Patient;
use App\Domains\Patients\Models\PatientShare;
use Illuminate\Support\Facades\Http;

echo "=== Diagnose Patient Visibility ===\n\n";

// Test doctor account
$testEmail = 'doctor@medical.test';
$loginResp = Http::post('https://prof-hosam-fekry.online/api/v1/login', [
    'email' => $testEmail,
    'password' => 'password',
    'device_name' => 'diagnostic',
]);

echo "Login with {$testEmail}: {$loginResp->status()}\n";
if ($loginResp->failed()) { echo $loginResp->body() . "\n"; exit(1); }

$token = $loginResp->json('token');
$userId = $loginResp->json('user.id');
echo "User ID: {$userId}, Name: {$loginResp->json('user.name')}\n\n";

try {
    $user = User::find($userId);
    if ($user) {
        echo "Local DB doctor ID: {$user->id}, Role: {$user->getRoleNames()->first()}\n";
        $myPatientsCount = Patient::where('primary_doctor_id', $user->id)->count();
        echo "Local patients where primary_doctor_id={$user->id}: {$myPatientsCount}\n";
    }
} catch (\Throwable $e) { echo "DB err: {$e->getMessage()}\n"; }

// Fetch via API
echo "\n=== API Response ===\n";
$resp = Http::withToken($token)->get('https://prof-hosam-fekry.online/api/v1/mobile/patients?per_page=200');
echo "Status: {$resp->status()}\n";
$data = $resp->json();
echo "Total meta: " . ($data['meta']['total'] ?? 'N/A') . "\n";
echo "Returned: " . count($data['data'] ?? []) . " patients\n\n";

$patients = $data['data'] ?? [];
$targetCodes = [966119, 162302, 510540, 570145, 920533];
echo "Target patients vs API response:\n";

foreach ($targetCodes as $code) {
    $found = null;
    foreach ($patients as $p) {
        if ($p['code'] == $code || $p['id'] == $code) { $found = $p; break; }
    }
    if ($found) {
        echo "  Code {$code}: FOUND - {$found['name']} (ID:{$found['id']}, primary:{$found['primary_doctor_id']})\n";
    } else {
        echo "  Code {$code}: NOT IN API RESPONSE\n";
    }
}

echo "\n=== All API-returned patients ===\n";
foreach ($patients as $p) {
    echo "  ID:{$p['id']} Code:{$p['code']} Name:{$p['name']} PrimaryDoctor:{$p['primary_doctor_id']}\n";
}
