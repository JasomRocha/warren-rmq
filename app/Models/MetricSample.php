<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MetricSample extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['areas' => 'array', 'rate_in' => 'float', 'rate_deliver' => 'float', 'rate_ack' => 'float', 'rate_redeliver' => 'float', 'capacity' => 'float'];
    }
}
