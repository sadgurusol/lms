<?php

namespace App\Policies;

use App\Authorization\Permissions;
use App\Models\CourseGrant;
use App\Models\QuestionBank;
use App\Models\User;

/**
 * A global bank (course_id null) is shared: anyone who may manage questions may
 * manage it. A course-scoped bank belongs to that course, so it also needs an
 * editing grant on the course — the same authority that lets you edit the course
 * itself. Admins bypass via Gate::before.
 */
class QuestionBankPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::QUESTION_MANAGE);
    }

    public function view(User $user, QuestionBank $bank): bool
    {
        return $this->manage($user, $bank);
    }

    public function manage(User $user, QuestionBank $bank): bool
    {
        if (! $user->can(Permissions::QUESTION_MANAGE)) {
            return false;
        }

        return $bank->isGlobal()
            || $user->hasGrantOn($bank->course_id, CourseGrant::EDITING);
    }
}
