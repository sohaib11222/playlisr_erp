<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

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

    /** Tasks that belong to this project (empty until weekly_tasks.project_id exists). */
    public function tasks()
    {
        return $this->hasMany(\App\WeeklyTask::class, 'project_id');
    }

    /** Who this project is assigned to (set by whoever creates/edits it) — see project_assignees. */
    public function assignees()
    {
        return $this->belongsToMany(\App\User::class, 'project_assignees', 'project_id', 'user_id')
            ->withTimestamps();
    }
}
