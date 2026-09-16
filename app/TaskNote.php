<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/** A single running-log entry on a task, visible to everyone who can see the task. */
class TaskNote extends Model
{
    protected $table = 'task_notes';
    protected $guarded = ['id'];

    public function author()
    {
        return $this->belongsTo(\App\User::class, 'user_id');
    }
}
