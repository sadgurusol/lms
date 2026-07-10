<?php

namespace App\Authorization;

/**
 * The permission catalogue and the role → permission mapping.
 *
 * Single source of truth: the seeder writes from here, the tests assert against
 * here, and `php artisan permission:show` reads the database that came from
 * here. Mirrors docs/03-rbac.md §3.
 */
final class Permissions
{
    // Identity
    public const USER_VIEW = 'user.view';

    public const USER_INVITE = 'user.invite';

    public const USER_UPDATE = 'user.update';

    public const USER_SUSPEND = 'user.suspend';

    public const ROLE_ASSIGN = 'role.assign';

    // Schemas
    public const SCHEMA_VIEW = 'schema.view';

    public const SCHEMA_CREATE = 'schema.create';

    public const SCHEMA_UPDATE = 'schema.update';

    public const SCHEMA_PUBLISH = 'schema.publish';

    public const SCHEMA_ARCHIVE = 'schema.archive';

    // Courses
    public const COURSE_VIEW_ANY = 'course.view.any';

    public const COURSE_VIEW_GRANTED = 'course.view.granted';

    public const COURSE_CREATE = 'course.create';

    public const COURSE_UPDATE = 'course.update';

    public const COURSE_DELETE = 'course.delete';

    public const COURSE_ARCHIVE = 'course.archive';

    public const COURSE_SUBMIT_REVIEW = 'course.submit_review';

    public const COURSE_REVIEW = 'course.review';

    public const COURSE_PUBLISH = 'course.publish';

    public const COURSE_GRANT = 'course.grant';

    // Content tree
    public const NODE_CREATE = 'node.create';

    public const NODE_UPDATE = 'node.update';

    public const NODE_DELETE = 'node.delete';

    public const NODE_MOVE = 'node.move';

    public const BLOCK_CREATE = 'block.create';

    public const BLOCK_UPDATE = 'block.update';

    public const BLOCK_DELETE = 'block.delete';

    // Assessments
    public const ASSESSMENT_MANAGE = 'assessment.manage';

    public const QUESTION_MANAGE = 'question.manage';

    public const ATTEMPT_TAKE = 'attempt.take';

    public const ATTEMPT_GRADE = 'attempt.grade';

    public const ATTEMPT_VIEW_ANY = 'attempt.view.any';

    // Media
    public const MEDIA_UPLOAD = 'media.upload';

    public const MEDIA_DELETE = 'media.delete';

    // Progress
    public const PROGRESS_VIEW_OWN = 'progress.view.own';

    public const PROGRESS_VIEW_CONTEXT = 'progress.view.context';

    public const PROGRESS_VIEW_ANY = 'progress.view.any';

    // B2B / commerce
    public const CLIENT_VIEW = 'client.view';

    public const CLIENT_MANAGE = 'client.manage';

    public const CLIENT_KEY_ROTATE = 'client.key.rotate';

    public const PRODUCT_VIEW = 'product.view';

    public const PRODUCT_MANAGE = 'product.manage';

    public const ENTITLEMENT_VIEW = 'entitlement.view';

    public const ENTITLEMENT_MANAGE = 'entitlement.manage';

    public const DELIVERY_VIEW = 'delivery.view';

    public const DELIVERY_REPLAY = 'delivery.replay';

    public const DEEPLINK_CREATE = 'deeplink.create';

    // Cross-cutting
    public const AUDIT_VIEW = 'audit.view';

    /** @return list<string> */
    public static function all(): array
    {
        return array_values((new \ReflectionClass(self::class))->getConstants());
    }

    /**
     * Role → permissions. `admin` is absent on purpose: it holds every
     * permission implicitly via the Gate::before bypass in AuthServiceProvider,
     * and enumerating them here would rot silently as the catalogue grows.
     *
     * @return array<string, list<string>>
     */
    public static function forRoles(): array
    {
        return [
            Roles::OPS => [
                self::CLIENT_VIEW, self::CLIENT_MANAGE, self::CLIENT_KEY_ROTATE,
                self::PRODUCT_VIEW, self::PRODUCT_MANAGE,
                self::ENTITLEMENT_VIEW, self::ENTITLEMENT_MANAGE,
                self::DELIVERY_VIEW, self::DELIVERY_REPLAY,
                self::PROGRESS_VIEW_OWN,
            ],

            Roles::CONTENT_AUTHOR => [
                self::SCHEMA_VIEW,
                self::COURSE_VIEW_GRANTED, self::COURSE_CREATE, self::COURSE_UPDATE,
                self::COURSE_SUBMIT_REVIEW, self::COURSE_GRANT,
                self::NODE_CREATE, self::NODE_UPDATE, self::NODE_DELETE, self::NODE_MOVE,
                self::BLOCK_CREATE, self::BLOCK_UPDATE, self::BLOCK_DELETE,
                self::ASSESSMENT_MANAGE, self::QUESTION_MANAGE,
                self::ATTEMPT_GRADE, self::ATTEMPT_VIEW_ANY,
                self::MEDIA_UPLOAD,
                self::PRODUCT_VIEW,
                self::PROGRESS_VIEW_OWN,
            ],

            Roles::CONTENT_REVIEWER => [
                self::SCHEMA_VIEW,
                self::COURSE_VIEW_GRANTED, self::COURSE_REVIEW,
                self::ATTEMPT_GRADE,
                self::PROGRESS_VIEW_OWN,
            ],

            Roles::INSTRUCTOR => [
                self::ATTEMPT_GRADE,
                self::DEEPLINK_CREATE,
                self::PROGRESS_VIEW_OWN, self::PROGRESS_VIEW_CONTEXT,
            ],

            Roles::LEARNER => [
                self::ATTEMPT_TAKE,
                self::PROGRESS_VIEW_OWN,
            ],
        ];
    }
}
