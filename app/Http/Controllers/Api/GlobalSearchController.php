<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ApiProxy;
use Illuminate\Http\Request;
use App\Domains\Patients\Models\Patient;
use App\Domains\Users\Models\User;

class GlobalSearchController extends Controller
{
    public function search(Request $request)
    {
        $query = $request->get('q', '');

        if (strlen($query) < 2) {
            return response()->json([]);
        }

        if (ApiProxy::isEnabled()) {
            return ApiProxy::proxyResponse(ApiProxy::get('/search', ['q' => $query]));
        }

        $results = [];

        $patients = Patient::where('name', 'like', "%{$query}%")
            ->orWhere('code', 'like', "%{$query}%")
            ->orWhere('phone', 'like', "%{$query}%")
            ->orWhere('diagnosis', 'like', "%{$query}%")
            ->take(5)
            ->get();

        foreach ($patients as $p) {
            $results[] = [
                'type' => 'Patient',
                'id' => $p->uuid,
                'title' => $p->name,
                'subtitle' => $p->code . ' • ' . ($p->diagnosis ?: 'No diagnosis'),
                'url' => route('patients.show', $p->uuid),
                'icon' => 'user'
            ];
        }

        $files = \App\Domains\Media\Models\PatientFile::with('patient')
            ->where('title', 'like', "%{$query}%")
            ->orWhere('desc', 'like', "%{$query}%")
            ->orWhere('file_name', 'like', "%{$query}%")
            ->take(5)
            ->get();

        foreach ($files as $f) {
            if ($f->patient) {
                $results[] = [
                    'type' => 'Media',
                    'id' => $f->uuid,
                    'title' => $f->title ?: $f->file_name,
                    'subtitle' => $f->desc ?: ($f->patient->name . ' • ' . $f->category),
                    'url' => route('patients.show', $f->patient->uuid) . '?open_file=' . $f->uuid,
                    'icon' => 'document'
                ];
            }
        }

        if ($request->user()->hasRole('super-admin')) {
            $doctors = User::role('doctor')
                ->where(function($q) use ($query) {
                    $q->where('name', 'like', "%{$query}%")
                      ->orWhere('code', 'like', "%{$query}%")
                      ->orWhere('specialization', 'like', "%{$query}%");
                })
                ->take(3)
                ->get();

            foreach ($doctors as $d) {
                $results[] = [
                    'type' => 'Doctor',
                    'id' => $d->id,
                    'title' => $d->name,
                    'subtitle' => $d->specialization . ' • ' . $d->code,
                    'url' => route('admin.doctors.show', $d->id),
                    'icon' => 'badge'
                ];
            }
        }

        return response()->json($results);
    }
}
