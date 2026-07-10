<?php

namespace App\Policies;

use App\Authorization\Permissions;
use App\Models\ContentBlock;
use App\Models\CourseNode;
use App\Models\User;

/**
 * Authority over a node never stands on its own — it flows from the course.
 */
class CourseNodePolicy
{
    public function __construct(private readonly CoursePolicy $coursePolicy) {}

    public function view(User $user, CourseNode $node): bool
    {
        return $this->coursePolicy->view($user, $node->course);
    }

    public function create(User $user, CourseNode $parent): bool
    {
        return $user->can(Permissions::NODE_CREATE)
            && $this->coursePolicy->update($user, $parent->course);
    }

    public function update(User $user, CourseNode $node): bool
    {
        return $user->can(Permissions::NODE_UPDATE)
            && $this->coursePolicy->update($user, $node->course);
    }

    public function move(User $user, CourseNode $node): bool
    {
        return $user->can(Permissions::NODE_MOVE)
            && $this->coursePolicy->update($user, $node->course);
    }

    public function delete(User $user, CourseNode $node): bool
    {
        return $user->can(Permissions::NODE_DELETE)
            && $this->coursePolicy->update($user, $node->course);
    }

    public function attachBlock(User $user, CourseNode $node): bool
    {
        return $user->can(Permissions::BLOCK_CREATE)
            && $this->coursePolicy->update($user, $node->course);
    }

    public function updateBlock(User $user, ContentBlock $block): bool
    {
        return $user->can(Permissions::BLOCK_UPDATE)
            && $this->coursePolicy->update($user, $block->courseNode->course);
    }
}
