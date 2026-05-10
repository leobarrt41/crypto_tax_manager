<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MonthlyAssetSnapshot
 *
 * Representa um "instantâneo" dos ativos que foram movimentados por um usuário
 * em uma determinada exchange durante um mês específico.
 *
 * Esta tabela serve como um índice para otimizar as importações de histórico,
 * permitindo que o sistema saiba quais ativos procurar em cada período mensal.
 */
class MonthlyAssetSnapshot extends Model
{
    use HasFactory;

    /**
     * O nome da tabela associada ao modelo.
     *
     * @var string
     */
    protected $table = 'monthly_asset_snapshots';

    /**
     * Os atributos que são atribuíveis em massa.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'exchange_id',
        'year',
        'month',
        'assets',
    ];

    /**
     * Os atributos que devem ser convertidos para tipos nativos.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'assets' => 'array', // Converte automaticamente o JSON da coluna 'assets' para um array PHP e vice-versa.
        'year' => 'integer',
        'month' => 'integer',
    ];

    /**
     * AJUSTE 1: Define o relacionamento com o modelo User.
     * Um snapshot pertence a um usuário.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * AJUSTE 2: Define o relacionamento com o modelo Exchange.
     * Um snapshot pertence a uma exchange.
     */
    public function exchange(): BelongsTo
    {
        return $this->belongsTo(Exchange::class);
    }
}
