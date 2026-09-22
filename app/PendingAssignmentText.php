<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class PendingAssignmentText extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function task()
    {
        return $this->belongsTo(\App\WeeklyTask::class, 'weekly_task_id');
    }

    public function project()
    {
        return $this->belongsTo(\App\Project::class, 'project_id');
    }

    public function user()
    {
        return $this->belongsTo(\App\User::class, 'user_id');
    }
}
