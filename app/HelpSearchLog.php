<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class HelpSearchLog extends Model
{
    protected $table = 'help_search_log';
    public $timestamps = false;
    protected $guarded = ['id'];

    protected $dates = ['created_at'];
}
