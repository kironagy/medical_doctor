<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\FileCategoryResource;
use App\Models\FileCategory;
use Illuminate\Http\Request;

class FileCategoryController extends Controller
{
    public function index(Request $request)
    {
        $perPage = min(max((int) $request->integer('per_page', 100), 1), 100);
        $search = trim((string) $request->query('search', $request->query('q', '')));

        $categories = FileCategory::query()
            ->when($search !== '', fn ($query) => $query->where('name', 'like', "%{$search}%"))
            ->orderBy('id')
            ->paginate($perPage);

        return FileCategoryResource::collection($categories);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['icon'] = $this->storeIcon($request) ?? $data['icon'] ?? null;
        $data['color'] = $data['color'] ?? '#3B82F6';

        $category = FileCategory::create($data);

        return (new FileCategoryResource($category))->response()->setStatusCode(201);
    }

    public function show(FileCategory $category)
    {
        return new FileCategoryResource($category);
    }

    public function update(Request $request, FileCategory $category)
    {
        $data = $this->validated($request);
        $data['icon'] = $this->storeIcon($request) ?? $data['icon'] ?? $category->icon;
        $data['color'] = $data['color'] ?? '#3B82F6';
        $category->update($data);

        return new FileCategoryResource($category->refresh());
    }

    public function destroy(FileCategory $category)
    {
        $category->delete();

        return response()->json(['message' => 'Deleted successfully']);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'uuid' => ['nullable', 'uuid'],
            'name' => ['required', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:500'],
            'icon_file' => ['nullable', 'image', 'max:2048'],
            'color' => ['nullable', 'string', 'max:30'],
            'client_updated_at' => ['nullable', 'date'],
        ]);
    }

    private function storeIcon(Request $request): ?string
    {
        if (! $request->hasFile('icon_file')) {
            return null;
        }

        return '/storage/'.$request->file('icon_file')->store('categories', 'public');
    }
}
