<?php

namespace App\Services\Catalog;

use App\Entitlements\EntitlementCache;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Product membership changes are the one entitlement write whose blast radius is
 * everyone: adding a course to a bundle grants it to every client and subscriber
 * holding that bundle, at once.
 *
 * So they are audited, and they bump the global entitlement cache version.
 */
final class ManageProduct
{
    public function __construct(private readonly EntitlementCache $cache) {}

    public function addCourse(Product $product, Course $course, ?User $actor = null): void
    {
        DB::transaction(function () use ($product, $course, $actor) {
            if ($product->courses()->whereKey($course->id)->exists()) {
                return;
            }

            $product->courses()->attach($course->id, ['added_at' => now()]);

            AuditLog::record($actor, 'product.course_added', $product,
                after: ['course_id' => $course->id, 'sku' => $product->sku],
            );
        });

        $this->cache->forgetEveryone();
    }

    public function removeCourse(Product $product, Course $course, ?User $actor = null): void
    {
        DB::transaction(function () use ($product, $course, $actor) {
            if ($product->courses()->detach($course->id) === 0) {
                return;
            }

            AuditLog::record($actor, 'product.course_removed', $product,
                before: ['course_id' => $course->id, 'sku' => $product->sku],
            );
        });

        $this->cache->forgetEveryone();
    }
}
