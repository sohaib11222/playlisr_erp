<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class TaskNote extends Model
{
    protected $guarded = ['id'];

    public function task()
    {
        return $this->belongsTo(\App\WeeklyTask::class, 'weekly_task_id');
    }

    public function author()
    {
        return $this->belongsTo(\App\User::class, 'user_id');
    }
}
