<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Category::class);

        $query = Category::query()->orderBy('title');

        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        $categories = $query->get();

        return response()->json([
            'data' => $categories->map(fn (Category $c) => $this->serializeCategory($c)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Category::class);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
            'image' => ['nullable', 'image', 'max:4096'],
        ]);

        $path = null;
        if ($request->hasFile('image'))
            $path = $request->file('image')->store('categories', 'public');

        $category = Category::query()->create([
            'title' => $validated['title'],
            'is_active' => $validated['is_active'],
            'image_path' => $path,
        ]);

        return response()->json([
            'data' => $this->serializeCategory($category),
        ], 201);
    }

    public function show(Category $category): JsonResponse
    {
        $this->authorize('view', $category);

        return response()->json([
            'data' => $this->serializeCategory($category),
        ]);
    }

    /**
     * FormData multipart: usar POST (no PATCH) desde el cliente si hay archivo.
     */
    public function update(Request $request, Category $category): JsonResponse
    {
        $this->authorize('update', $category);

        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'required', 'boolean'],
            'image' => ['sometimes', 'nullable', 'image', 'max:4096'],
            'remove_image' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('title', $validated))
            $category->title = $validated['title'];

        if (array_key_exists('is_active', $validated))
            $category->is_active = $validated['is_active'];

        if ($request->boolean('remove_image')) {
            $this->deleteStoredImage($category);
            $category->image_path = null;
        }

        if ($request->hasFile('image')) {
            $this->deleteStoredImage($category);
            $category->image_path = $request->file('image')->store('categories', 'public');
        }

        $category->save();

        return response()->json([
            'data' => $this->serializeCategory($category->fresh()),
        ]);
    }

    public function destroy(Category $category): JsonResponse
    {
        $this->authorize('delete', $category);

        $this->deleteStoredImage($category);
        $category->delete();

        return response()->json(['message' => 'Categoría eliminada.']);
    }

    private function deleteStoredImage(Category $category): void
    {
        if ($category->image_path && Storage::disk('public')->exists($category->image_path))
            Storage::disk('public')->delete($category->image_path);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeCategory(Category $category): array
    {
        $imageUrl = null;
        if ($category->image_path)
            $imageUrl = asset('storage/'.$category->image_path);

        return [
            'id' => $category->id,
            'title' => $category->title,
            'image_url' => $imageUrl,
            'is_active' => (bool) $category->is_active,
            'created_at' => $category->created_at?->toIso8601String(),
        ];
    }
}
