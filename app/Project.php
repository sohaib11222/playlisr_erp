<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    protected $guarded = ['id'];

    public function creator()
    {
        return $this->belongsTo(\App\User::class, 'created_by');
    }

    public function startedBy()
    {
        return $this->belongsTo(\App\User::class, 'started_by');
    }

    public function completedBy()
    {
        return $this->belongsTo(\App\User::class, 'completed_by');
    }

    /** Everyone credited as having joined in on this project. */
    public function contributors()
    {
        return $this->belongsToMany(\App\User::class, 'project_contributors', 'project_id', 'user_id')
            ->withPivot('joined_at')
            ->withTimestamps();
    }
}
