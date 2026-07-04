<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CategoryController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $customCategories = $user->preferences['custom_categories'] ?? [];
        $defaultCategories = config('categories', []);
        $merged = $this->mergeCategories($defaultCategories, $customCategories);
        return response()->json($merged);
    }

    public function update(Request $request)
    {
        $user = $request->user();
        $validated = $request->validate([
            'categories' => 'required|array',
            'categories.*.slug' => 'required|string',
            'categories.*.name' => 'required|string',
            'categories.*.icon' => 'nullable|string',
            'categories.*.color' => 'nullable|string',
            'categories.*.order' => 'nullable|integer',
            'categories.*.is_visible' => 'nullable|boolean',
        ]);

        $preferences = $user->preferences ?? [];
        $preferences['custom_categories'] = $validated['categories'];
        $user->update(['preferences' => $preferences]);

        Cache::forget("user_categories_{$user->id}");

        return response()->json($validated['categories']);
    }

    public function addCategory(Request $request)
    {
        $user = $request->user();
        $validated = $request->validate([
            'slug' => 'required|string|unique_custom_category',
            'name' => 'required|string|max:255',
            'icon' => 'nullable|string',
            'color' => 'nullable|string',
            'order' => 'nullable|integer',
        ]);

        $preferences = $user->preferences ?? [];
        $customCategories = $preferences['custom_categories'] ?? [];

        $customCategories[] = [
            'slug' => $validated['slug'],
            'name' => $validated['name'],
            'icon' => $validated['icon'] ?? 'folder',
            'color' => $validated['color'] ?? '#6b7280',
            'order' => $validated['order'] ?? 99,
            'is_visible' => true,
        ];

        $preferences['custom_categories'] = $customCategories;
        $user->update(['preferences' => $preferences]);

        return response()->json($customCategories);
    }

    public function deleteCategory(Request $request, string $slug)
    {
        $user = $request->user();
        $preferences = $user->preferences ?? [];
        $customCategories = $preferences['custom_categories'] ?? [];
        $customCategories = array_values(array_filter($customCategories, fn($c) => ($c['slug'] ?? '') !== $slug));
        $preferences['custom_categories'] = $customCategories;
        $user->update(['preferences' => $preferences]);

        return response()->json(['message' => 'Category removed']);
    }

    private function mergeCategories(array $defaults, array $custom): array
    {
        $customBySlug = [];
        foreach ($custom as $c) {
            $customBySlug[$c['slug']] = $c;
        }

        $result = [];
        $seen = [];

        foreach ($defaults as $def) {
            $slug = $def['slug'];
            $seen[$slug] = true;
            if (isset($customBySlug[$slug])) {
                $merged = array_merge($def, $customBySlug[$slug]);
                if (isset($merged['is_visible']) && !$merged['is_visible']) continue;
                $result[] = $merged;
            } else {
                $result[] = $def;
            }
        }

        foreach ($custom as $c) {
            $slug = $c['slug'] ?? '';
            if (!$slug || isset($seen[$slug])) continue;
            $seen[$slug] = true;
            if (isset($c['is_visible']) && !$c['is_visible']) continue;
            $result[] = $c;
        }

        usort($result, fn($a, $b) => ($a['order'] ?? 99) - ($b['order'] ?? 99));
        return $result;
    }
}
