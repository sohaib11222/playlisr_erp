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
}
