<?php

namespace App\Policies;

use App\Authorization\Permissions;
use App\Models\ContentBlock;
use App\Models\User;

/**
 * Authority over a block flows from its course, exactly as a node's does.
 *
 * These live on their own policy, not on CourseNodePolicy: Laravel resolves a
 * policy from the *argument's* class, so `Gate::authorize('update', $block)`
 * only ever reaches a policy registered for ContentBlock. The node-scoped
 * `attachBlock` check stays on CourseNodePolicy, where its argument is a node.
 */
class ContentBlockPolicy
{
    public function __construct(private readonly CoursePolicy $coursePolicy) {}

    public function view(User $user, ContentBlock $block): bool
    {
        return $this->coursePolicy->view($user, $block->courseNode->course);
    }

    public function update(User $user, ContentBlock $block): bool
    {
        return $user->can(Permissions::BLOCK_UPDATE)
            && $this->coursePolicy->update($user, $block->courseNode->course);
    }

    public function delete(User $user, ContentBlock $block): bool
    {
        return $user->can(Permissions::BLOCK_DELETE)
            && $this->coursePolicy->update($user, $block->courseNode->course);
    }
}
