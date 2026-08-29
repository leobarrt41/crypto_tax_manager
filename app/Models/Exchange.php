<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Exchange extends Model
{
    protected $fillable = ['name', 'country_code', 'description'];

    public function apiKeys()
    {
        return $this->hasMany(UserApiKey::class);
    }

    public function taxRules()
{
    return $this->hasMany(TaxRule::class);
}




}
