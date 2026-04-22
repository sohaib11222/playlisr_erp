<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class ChartPickImport extends Model
{
    protected $table = 'chart_pick_imports';

    protected $guarded = ['id'];

    protected $casts = [
        'week_of' => 'date',
    ];

    public function picks()
    {
        return $this->hasMany(ChartPick::class, 'import_id');
    }
}
