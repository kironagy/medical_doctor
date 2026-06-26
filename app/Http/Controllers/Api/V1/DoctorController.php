<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class DoctorController extends Controller
{
    public function index(Request $request)
    {
        $perPage = min(max((int) $request->integer('per_page', 25), 1), 100);
        $search = trim((string) $request->query('search', $request->query('q', '')));

        $doctors = User::where('role', 'doctor')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('specialization', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('id')
            ->paginate($perPage);

        return UserResource::collection($doctors);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['password'] = Hash::make($data['password']);
        $data['role'] = 'doctor';

        $doctor = User::create($data);

        return (new UserResource($doctor))->response()->setStatusCode(201);
    }

    public function show(User $doctor)
    {
        abort_unless($doctor->role === 'doctor', 404);

        return new UserResource($doctor);
    }

    public function update(Request $request, User $doctor)
    {
        abort_unless($doctor->role === 'doctor', 404);
        $data = $this->validated($request, $doctor);

        if (! empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $doctor->update($data);

        return new UserResource($doctor->refresh());
    }

    public function destroy(User $doctor)
    {
        abort_unless($doctor->role === 'doctor', 404);
        $doctor->delete();

        return response()->json(['message' => 'Deleted successfully']);
    }

    private function validated(Request $request, ?User $doctor = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,'.($doctor?->id ?? 'NULL')],
            'password' => [$doctor ? 'nullable' : 'required', 'string', 'min:6'],
            'phone' => ['nullable', 'string', 'max:255'],
            'specialization' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
