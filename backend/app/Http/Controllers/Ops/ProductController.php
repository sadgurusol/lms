<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Product;
use App\Services\Catalog\ManageProduct;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The catalogue: products bundle published courses. Adding or removing a course
 * is the one write whose blast radius is everyone holding the product, so it
 * goes through ManageProduct (audited, busts the entitlement cache) — never a
 * raw pivot write.
 */
class ProductController extends Controller
{
    private const KINDS = ['course', 'bundle', 'catalog'];

    private const STATUSES = ['draft', 'active', 'retired'];

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Product::class);

        $products = Product::query()
            ->withCount('courses')
            ->orderBy('name')
            ->get()
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'sku' => $p->sku,
                'name' => $p->name,
                'kind' => $p->kind,
                'status' => $p->status,
                'course_count' => $p->courses_count,
            ]);

        return Inertia::render('products/Index', [
            'products' => $products,
            'options' => ['kinds' => self::KINDS, 'statuses' => self::STATUSES],
            'can' => ['create' => Gate::allows('create', Product::class)],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Product::class);

        $data = $request->validate($this->rules());

        $product = Product::create([
            'sku' => $data['sku'],
            'name' => $data['name'],
            'kind' => $data['kind'],
            'status' => $data['status'],
        ]);

        return redirect()
            ->route('ops.products.show', $product)
            ->with('success', "Created product “{$product->name}”.");
    }

    public function show(Request $request, Product $product): Response
    {
        Gate::authorize('view', $product);

        $product->load('courses:id,title,workflow_state,latest_publication_id')->loadCount('courses');
        $memberIds = $product->courses->pluck('id')->all();

        return Inertia::render('products/Show', [
            'product' => [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'kind' => $product->kind,
                'status' => $product->status,
            ],
            'courses' => $product->courses->map(fn (Course $c) => [
                'id' => $c->id,
                'title' => $c->title,
                'is_published' => $c->latest_publication_id !== null,
            ])->values(),
            // Courses that could be added: everything not already in the product.
            // A course need not be published to be bundled, but we flag it so ops
            // knows an unpublished course grants nothing until it is published.
            'available' => Course::query()
                ->whereNotIn('id', $memberIds)
                ->orderBy('title')
                ->get(['id', 'title', 'latest_publication_id'])
                ->map(fn (Course $c) => [
                    'id' => $c->id,
                    'title' => $c->title,
                    'is_published' => $c->latest_publication_id !== null,
                ]),
            'options' => ['kinds' => self::KINDS, 'statuses' => self::STATUSES],
            'can' => ['manage' => Gate::allows('manage', $product)],
        ]);
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        Gate::authorize('manage', $product);

        $data = $request->validate($this->rules($product));

        $product->update([
            'sku' => $data['sku'],
            'name' => $data['name'],
            'kind' => $data['kind'],
            'status' => $data['status'],
        ]);

        return back()->with('success', 'Product saved.');
    }

    public function addCourse(Request $request, Product $product, ManageProduct $catalog): RedirectResponse
    {
        Gate::authorize('manage', $product);

        $data = $request->validate([
            'course_id' => ['required', 'uuid', Rule::exists('courses', 'id')],
        ]);

        $catalog->addCourse($product, Course::findOrFail($data['course_id']), $request->user());

        return back()->with('success', 'Course added to product.');
    }

    public function removeCourse(Request $request, Product $product, Course $course, ManageProduct $catalog): RedirectResponse
    {
        Gate::authorize('manage', $product);

        $catalog->removeCourse($product, $course, $request->user());

        return back()->with('success', 'Course removed from product.');
    }

    /** @return array<string, mixed> */
    private function rules(?Product $product = null): array
    {
        return [
            'sku' => [
                'required', 'string', 'max:60', 'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('products', 'sku')->ignore($product?->id),
            ],
            'name' => ['required', 'string', 'max:160'],
            'kind' => ['required', Rule::in(self::KINDS)],
            'status' => ['required', Rule::in(self::STATUSES)],
        ];
    }
}
