<?php

namespace App\Policies;

use App\Authorization\Permissions;
use App\Models\Assessment;
use App\Models\User;

/**
 * Authority over an assessment flows from its course, like a node's or block's.
 * Managing one needs the assessment permission plus the authority to edit the
 * course it hangs on.
 */
class AssessmentPolicy
{
    public function __construct(private readonly CoursePolicy $coursePolicy) {}

    public function view(User $user, Assessment $assessment): bool
    {
        return $this->coursePolicy->view($user, $assessment->course);
    }

    public function manage(User $user, Assessment $assessment): bool
    {
        return $user->can(Permissions::ASSESSMENT_MANAGE)
            && $this->coursePolicy->update($user, $assessment->course);
    }
}
