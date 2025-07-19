<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaxRule extends Model
{
    protected $fillable = [
        'country',
        'exchange_name',
        'has_exemption_limit',
        'exemption_limit_value',
        'start_date',
        'end_date',
    ];

    public function exchange()
{
    return $this->belongsTo(Exchange::class);
}

}
