<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class ChartPick extends Model
{
    protected $table = 'chart_picks';

    protected $guarded = ['id'];

    protected $casts = [
        'week_of' => 'date',
        'is_new_release' => 'boolean',
    ];

    public function import()
    {
        return $this->belongsTo(ChartPickImport::class, 'import_id');
    }
}
