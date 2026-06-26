<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Patient;
use App\Models\PatientVisit;
use App\Models\FileCategory;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function index()
    {
        $doctorsCount = User::where('role', 'doctor')->count();
        $patientsCount = Patient::count();
        $profits = PatientVisit::sum('cost');

        $doctors = User::where('role', 'doctor')->get();
        $categories = FileCategory::all();

        return view('admin.index', compact('doctorsCount', 'patientsCount', 'profits', 'doctors', 'categories'));
    }

    public function storeDoctor(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'email' => 'required|email|unique:users',
            'password' => 'required|string|min:6',
            'phone' => 'nullable|string',
            'specialization' => 'nullable|string',
        ]);

        User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => bcrypt($request->password),
            'role' => 'doctor',
            'phone' => $request->phone,
            'specialization' => $request->specialization,
        ]);

        return redirect()->back()->with('success', 'تم إضافة الطبيب بنجاح');
    }

    public function updateDoctor(Request $request, $id)
    {
        $user = User::findOrFail($id);
        $request->validate([
            'name' => 'required|string',
            'email' => 'required|email|unique:users,email,' . $id,
            'password' => 'nullable|string|min:6',
            'phone' => 'nullable|string',
            'specialization' => 'nullable|string',
        ]);

        $data = [
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'specialization' => $request->specialization,
        ];

        if ($request->filled('password')) {
            $data['password'] = bcrypt($request->password);
        }

        $user->update($data);
        return redirect()->back()->with('success', 'تم تعديل بيانات الطبيب بنجاح');
    }

    public function destroyDoctor($id)
    {
        $user = User::findOrFail($id);
        if ($user->role === 'doctor') {
            $user->delete();
        }
        return redirect()->back()->with('success', 'تم حذف الطبيب بنجاح');
    }

    public function storeCategory(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'icon' => 'nullable|image|max:2048', // Image upload
            'color' => 'nullable|string',
        ]);

        $iconPath = null;
        if ($request->hasFile('icon')) {
            $file = $request->file('icon');
            $filename = time() . '_' . $file->getClientOriginalName();
            $file->move(public_path('uploads/categories'), $filename);
            $iconPath = 'uploads/categories/' . $filename;
        }

        FileCategory::create([
            'name' => $request->name,
            'icon' => $iconPath,
            'color' => $request->color ?? '#3B82F6',
        ]);

        return redirect()->back()->with('success', 'تم إضافة القسم بنجاح');
    }

    public function updateCategory(Request $request, $id)
    {
        $cat = FileCategory::findOrFail($id);
        
        $request->validate([
            'name' => 'required|string',
            'icon' => 'nullable|image|max:2048',
            'color' => 'nullable|string',
        ]);

        $iconPath = $cat->icon;
        if ($request->hasFile('icon')) {
            if ($cat->icon && file_exists(public_path($cat->icon))) {
                @unlink(public_path($cat->icon));
            }
            $file = $request->file('icon');
            $filename = time() . '_' . $file->getClientOriginalName();
            $file->move(public_path('uploads/categories'), $filename);
            $iconPath = 'uploads/categories/' . $filename;
        }

        $cat->update([
            'name' => $request->name,
            'icon' => $iconPath,
            'color' => $request->color ?? '#3B82F6',
        ]);

        return redirect()->back()->with('success', 'تم تعديل القسم بنجاح');
    }

    public function destroyCategory($id)
    {
        $cat = FileCategory::findOrFail($id);
        if ($cat->icon && file_exists(public_path($cat->icon))) {
            @unlink(public_path($cat->icon));
        }
        $cat->delete();
        return redirect()->back()->with('success', 'تم حذف القسم بنجاح');
    }
}
