<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Domains\Patients\Models\Patient;
use App\Domains\Media\Models\PatientFile;
use App\Domains\Users\Models\User;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function search(Request $request)
    {
        $query = $request->get('q');

        if (!$query || strlen($query) < 2) {
            return response()->json(['results' => []]);
        }

        $results = [];

        $patients = Patient::where(function ($q) use ($query) {
            $q->where('name', 'like', "%{$query}%")
              ->orWhere('code', 'like', "%{$query}%")
              ->orWhere('phone', 'like', "%{$query}%")
              ->orWhere('diagnosis', 'like', "%{$query}%");
        })->limit(5)->get();

        foreach ($patients as $patient) {
            $results[] = [
                'type' => 'patient',
                'id' => $patient->uuid,
                'title' => $patient->name,
                'subtitle' => $patient->code ?? $patient->phone,
            ];
        }

        $files = PatientFile::where(function ($q) use ($query) {
            $q->where('title', 'like', "%{$query}%")
              ->orWhere('desc', 'like', "%{$query}%")
              ->orWhere('file_name', 'like', "%{$query}%");
        })->limit(5)->get();

        foreach ($files as $file) {
            $results[] = [
                'type' => 'file',
                'id' => $file->uuid,
                'title' => $file->title ?? $file->file_name,
                'subtitle' => $file->type,
            ];
        }

        if ($request->user()->hasRole('super-admin')) {
            $doctors = User::role('doctor')->where(function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                  ->orWhere('code', 'like', "%{$query}%")
                  ->orWhere('specialization', 'like', "%{$query}%");
            })->limit(3)->get();

            foreach ($doctors as $doctor) {
                $results[] = [
                    'type' => 'doctor',
                    'id' => (string) $doctor->id,
                    'title' => $doctor->name,
                    'subtitle' => $doctor->specialization,
                ];
            }
        }

        return response()->json(['results' => $results]);
    }
}
