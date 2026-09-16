<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class WeeklyTask extends Model
{
    protected $table = 'weekly_tasks';
    protected $guarded = ['id'];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'repeat_daily' => 'boolean',
        'repeat_weekly' => 'boolean',
        'last_reset_date' => 'date',
        'requires_photo' => 'boolean',
        'photo_confirmed_at' => 'datetime',
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

    public function photoConfirmedBy()
    {
        return $this->belongsTo(\App\User::class, 'photo_confirmed_by');
    }

    /** Running note log, oldest first — everyone who can see the task sees these. */
    public function notes()
    {
        return $this->hasMany(\App\TaskNote::class, 'weekly_task_id')->orderBy('created_at');
    }

    /** Everyone assigned to work on this task. */
    public function assignees()
    {
        return $this->belongsToMany(\App\User::class, 'task_assignees', 'task_id', 'user_id')
            ->withTimestamps();
    }

    /** The original "repeat daily" task this instance was generated from, if any. */
    public function repeatRoot()
    {
        return $this->belongsTo(\App\WeeklyTask::class, 'repeat_of');
    }
}
