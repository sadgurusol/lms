<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A class or section, provisioned from the SIS launch.
 *
 * This is the B2B cohort, and it comes for free. Do not build a parallel
 * `cohorts` table for clients that already have one.
 *
 * @property string $id
 * @property string $client_id
 * @property string $external_context_id
 * @property string|null $title
 * @property string|null $type
 */
#[Fillable(['client_id', 'external_context_id', 'title', 'type'])]
class ClientContext extends Model
{
    use HasUuids;
}
