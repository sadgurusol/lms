<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Records create/update/delete against the audited model.
 *
 * Only dirty attributes are stored, minus anything the model hides — an audit
 * row containing a password hash is a liability, not a control.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn ($model) => $model->audit('created', null, $model->auditAttributes()));

        static::updated(function ($model) {
            $changed = array_keys($model->getDirty());
            if ($changed === []) {
                return;
            }

            $model->audit(
                'updated',
                $model->auditAttributes(array_intersect_key($model->getOriginal(), array_flip($changed))),
                $model->auditAttributes($model->getDirty()),
            );
        });

        static::deleted(fn ($model) => $model->audit('deleted', $model->auditAttributes(), null));
    }

    /** Override to widen or narrow the audited action name. */
    protected function auditAction(string $event): string
    {
        return class_basename(static::class).'.'.$event;
    }

    protected function auditAttributes(?array $attributes = null): array
    {
        $attributes ??= $this->getAttributes();

        return array_diff_key($attributes, array_flip($this->getHidden()));
    }

    protected function audit(string $event, ?array $before, ?array $after): void
    {
        $actor = Auth::user();

        AuditLog::record(
            $actor instanceof User ? $actor : null,
            $this->auditAction($event),
            $this,
            $before,
            $after,
        );
    }
}
