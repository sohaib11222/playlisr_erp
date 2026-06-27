<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-employee draft of the mass-create product form. See
 * 2026_06_26_120000_create_mass_create_drafts_table migration.
 */
class MassCreateDraft extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'payload' => 'array',
    ];
}
