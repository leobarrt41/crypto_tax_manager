<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Wallet extends Model
{
    use HasFactory;

    /**
     * Os campos que podem ser preenchidos em massa.
     */
    protected $fillable = [
        'user_id',     // ID do usuário dono da carteira
        'name',        // Nome da carteira (Trust Wallet, Metamask, etc.)
        'network_id',  // ID da rede (BSC, Ethereum, Polygon, etc.)
        'address',     // Endereço público da carteira
        'api_key',     // Chave de API para integração externa
        'description', // Descrição opcional
    ];

    /**
     * Relacionamento: a carteira pertence a um usuário.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relacionamento: a carteira pertence a uma rede (ex: BSC, ETH).
     */
    public function network()
    {
        return $this->belongsTo(Network::class);
    }

    /**
     * Relacionamento: a carteira possui muitos saldos.
     */
    public function balances()
    {
        return $this->hasMany(WalletBalance::class);
    }

    /**
     * Escopo para buscar carteiras por ID da rede.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $networkId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByNetwork($query, $networkId)
    {
        return $query->where('network_id', $networkId);
    }

    /**
     * Escopo para filtrar carteiras pelo nome, usando correspondência parcial.
     * Permite buscar por parte do nome da carteira (ex: "Trust", "Meta").
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $name
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByName($query, $name)
    {
        return $query->where('name', 'LIKE', "%{$name}%");
    }

    /**
     * Verifica se a carteira possui uma chave de API válida.
     *
     * @return bool
     */
    public function hasApiKey()
    {
        return !empty($this->api_key);
    }

    public function transactions()
{
    return $this->morphMany(Transaction::class, 'source');
}

}
