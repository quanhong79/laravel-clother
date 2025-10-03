<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    /** Danh sách (admin) + filter q, parent_id */
    public function index(Request $request)
    {
        $q        = trim((string) $request->get('q', ''));
        $parentId = $request->get('parent_id');

        $categories = Category::query()
            ->with('parent:id,name')
            ->withCount('children')
            ->when($q !== '', function ($qr) use ($q) {
                $qr->where(function ($w) use ($q) {
                    $w->where('name', 'like', "%{$q}%")
                      ->orWhere('slug', 'like', "%{$q}%");
                });
            })
            ->when(is_numeric($parentId), function ($qr) use ($parentId) {
                if ((int) $parentId === 0) {
                    $qr->whereNull('parent_id'); // chỉ gốc
                } else {
                    $qr->where('parent_id', (int) $parentId);
                }
            })
            ->orderBy('name')
            ->paginate(20)
            ->appends($request->query());

        // Dropdown cha
        $parents = Category::whereNull('parent_id')
            ->orderBy('name')
            ->get(['id','name']);

        return view('admin.categories.index', compact('categories','parents'));
    }

    /** Form tạo (admin) */
    public function create()
{
    // Có thể chỉ lấy danh mục gốc, hoặc tất cả 
    $parents = Category::orderBy('name')->get(['id','name','parent_id']);
    return view('admin.categories.create', compact('parents'));
}

    /** Lưu mới (admin) */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name'      => 'required|string|max:255',
            'slug'      => 'nullable|string|max:255|unique:categories,slug',
            'parent_id' => 'nullable|integer|exists:categories,id',
        ]);

        // slug auto + unique
        $slug = $data['slug'] ?? Str::slug($data['name']);
        $data['slug'] = $this->makeUniqueSlug($slug);

        Category::create($data);

        return redirect()->route('admin.categories.index')
                         ->with('success', 'Đã tạo danh mục.');
    }

    /** Hiển thị (tuỳ dùng) */
    public function show(Category $category)
    {
        return view('categories.show', compact('category'));
    }

    /** Form sửa (admin) */
    public function edit(Category $category)
{
    // Loại trừ chính nó và (khuyến nghị) con cháu của nó khỏi danh sách cha
    $excludeIds = $this->collectDescendantIds($category);
    $excludeIds[] = $category->id;

    $parents = Category::whereNotIn('id', $excludeIds)
        ->orderBy('name')->get(['id','name','parent_id']);

    return view('admin.categories.edit', compact('category','parents'));
}

    /** Cập nhật (admin) */
    public function update(Request $request, Category $category)
    {
        $data = $request->validate([
            'name'      => 'required|string|max:255',
            'slug'      => [
                'nullable','string','max:255',
                Rule::unique('categories','slug')->ignore($category->id),
            ],
            'parent_id' => [
                'nullable','integer','exists:categories,id',
                Rule::notIn([$category->id]), // không cho parent là chính nó
            ],
        ]);

        $newSlug = $data['slug'] ?? Str::slug($data['name']);
        if ($newSlug !== $category->slug) {
            $data['slug'] = $this->makeUniqueSlug($newSlug, $category->id);
        }

        $category->update($data);

        return redirect()->route('admin.categories.index')
                         ->with('success', 'Đã cập nhật danh mục.');
    }

    /** Xoá (admin) */
    public function destroy(Category $category)
    {
        // Gỡ liên kết con (đưa lên root) trước khi xoá
        Category::where('parent_id', $category->id)->update(['parent_id' => null]);
        $category->delete();

        return redirect()->route('admin.categories.index')
                         ->with('success', 'Đã xoá danh mục.');
    }

    /** Tạo slug duy nhất (-2, -3 ...) */
    private function makeUniqueSlug(string $baseSlug, ?int $ignoreId = null): string
    {
        $slug = Str::slug($baseSlug) ?: Str::random(8);
        $original = $slug;
        $i = 2;

        while (
            Category::where('slug', $slug)
                ->when($ignoreId, fn($q) => $q->where('id','!=',$ignoreId))
                ->exists()
        ) {
            $slug = $original.'-'.$i;
            $i++;
        }
        return $slug;
    }
    private function collectDescendantIds(Category $root): array
{
    $ids = [];
    $stack = $root->children()->get(['id']);
    while ($stack->isNotEmpty()) {
        $child = $stack->pop();
        $ids[] = $child->id;
        $stack = $stack->merge(
            Category::where('parent_id', $child->id)->get(['id'])
        );
    }
    return $ids;
}
}
