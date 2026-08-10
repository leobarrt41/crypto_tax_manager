<?php

namespace App\Services;

use App\Models\User;
use App\Models\Transaction;
use App\Models\UserApiKey;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * IN1888Service
 *
 * Gera o arquivo de movimentação mensal de criptoativos no formato exigido
 * pela Instrução Normativa RFB nº 1.888/2019 (e alterações posteriores).
 *
 * Regras de obrigatoriedade:
 *  - Exchanges NACIONAIS (country = 'BR'): NÃO entram no arquivo.
 *    A obrigação de informar é da própria corretora, não do contribuinte.
 *  - Exchanges ESTRANGEIRAS e carteiras próprias: entram no cálculo do
 *    volume mensal e nos registros 0720.
 *  - Obrigatoriedade: volume mensal > R$ 30.000.
 *
 * Campos do model Transaction utilizados (corrigidos):
 *  - from_asset / to_asset  (não crypto_asset — campo inexistente)
 *  - from_amount / to_amount (não amount — campo inexistente)
 *  - total_brl               (não value_brl — campo inexistente)
 *  - price                   (preço unitário)
 *  - type                    (trade, convert, deposit, withdrawal, etc.)
 *  - source_type / source_id (para identificar a exchange via UserApiKey)
 */
class IN1888Service
{
    // ─── Ponto de entrada principal ──────────────────────────────────────────

    public function generateMonthlyFile(int $userId, int $month, int $year): array
    {
        $user         = User::findOrFail($userId);
        $transactions = $this->getMonthlyTransactions($userId, $month, $year);
        $totalVolume  = $transactions->sum('total_brl');

        if ($totalVolume <= 30000) {
            return [
                'required'           => false,
                'message'            => 'Volume mensal inferior a R$ 30.000. IN 1888 não é obrigatória.',
                'total_volume'       => $totalVolume,
                'transactions_count' => $transactions->count(),
            ];
        }

        $content  = $this->buildFileContent($transactions, $user, $month, $year);
        $filename = $this->generateFilename($user, $month, $year);

        Storage::disk('local')->put("in1888/{$filename}", $content);

        Log::info("[IN1888] Arquivo gerado para usuário {$userId} — {$month}/{$year}: {$filename}");

        return [
            'required'           => true,
            'filename'           => $filename,
            'content'            => $content,
            'total_volume'       => $totalVolume,
            'transactions_count' => $transactions->count(),
            'file_path'          => storage_path("app/in1888/{$filename}"),
            'download_url'       => route('in1888.download', $filename),
        ];
    }

    // ─── Consulta de transações ───────────────────────────────────────────────

    /**
     * Retorna as transações do mês que devem constar na IN 1888.
     * Exclui exchanges nacionais (country = 'BR').
     */
    public function getMonthlyTransactions(int $userId, int $month, int $year)
    {
        $nationalApiKeyIds = UserApiKey::where('user_id', $userId)
            ->whereHas('exchange', fn ($q) => $q->where('country', 'BR'))
            ->pluck('id')
            ->toArray();

        return Transaction::where('user_id', $userId)
            ->whereYear('date', $year)
            ->whereMonth('date', $month)
            ->whereIn('type', ['trade', 'convert', 'deposit', 'withdrawal', 'fiat_buy', 'fiat_sell'])
            ->where(function ($query) use ($nationalApiKeyIds) {
                $query->where(function ($q) use ($nationalApiKeyIds) {
                    $q->where('source_type', UserApiKey::class)
                      ->whereNotIn('source_id', $nationalApiKeyIds);
                })->orWhere(function ($q) {
                    $q->where('source_type', '!=', UserApiKey::class)
                      ->orWhereNull('source_type');
                });
            })
            ->orderBy('date')
            ->get();
    }

    // ─── Construção do arquivo ────────────────────────────────────────────────

    private function buildFileContent($transactions, User $user, int $month, int $year): string
    {
        $lines   = [];
        $lines[] = $this->buildRecord0000($user, $month, $year);
        $lines[] = $this->buildRecord0010($user);

        foreach ($transactions as $transaction) {
            $record = $this->buildRecord0720($transaction);
            if ($record !== null) {
                $lines[] = $record;
            }
        }

        $lines[] = $this->buildRecord9999(count($lines));

        return implode("\r\n", $lines);
    }

    private function buildRecord0000(User $user, int $month, int $year): string
    {
        return sprintf(
            "0000%s%02d%04d%s%s",
            str_pad(preg_replace('/\D/', '', $user->cpf ?? ''), 11, '0', STR_PAD_LEFT),
            $month,
            $year,
            str_pad('', 8, ' '),
            'IN1888'
        );
    }

    private function buildRecord0010(User $user): string
    {
        return sprintf(
            "0010%s%s%s",
            str_pad(preg_replace('/\D/', '', $user->cpf ?? ''), 11, '0', STR_PAD_LEFT),
            str_pad(mb_strtoupper($user->name ?? ''), 60, ' ', STR_PAD_RIGHT),
            str_pad('', 29, ' ')
        );
    }

    /**
     * Registro 0720 — Operação com criptoativo (136 caracteres).
     * Campos corrigidos: from_asset, from_amount, total_brl (não crypto_asset/amount/value_brl).
     */
    private function buildRecord0720(Transaction $transaction): ?string
    {
        try {
            $date = $transaction->date instanceof Carbon
                ? $transaction->date
                : Carbon::parse($transaction->date);

            [$asset, $amount] = $this->resolveAssetAndAmount($transaction);
            $valueBrl         = (float) ($transaction->total_brl ?? 0);
            $price            = (float) ($transaction->price ?? 0);
            $exchangeCode     = $this->resolveExchangeCode($transaction);

            return sprintf(
                "0720%s%s%s%s%s%s%s",
                $date->format('dmY'),                                                    //  8
                str_pad($this->getOperationCode($transaction->type), 2, '0', STR_PAD_LEFT), //  2
                str_pad(mb_strtoupper($asset), 10, ' ', STR_PAD_RIGHT),                 // 10
                str_pad($this->formatAmount($amount), 18, '0', STR_PAD_LEFT),           // 18
                str_pad($this->formatValue($price), 18, '0', STR_PAD_LEFT),             // 18
                str_pad($this->formatValue($valueBrl), 18, '0', STR_PAD_LEFT),          // 18
                str_pad(mb_strtoupper($exchangeCode), 60, ' ', STR_PAD_RIGHT)           // 60
            );                                                                           // = 134 + 4 (0720) = 136 ✓

        } catch (\Exception $e) {
            Log::error("[IN1888] Erro no registro 0720 para transação {$transaction->id}: " . $e->getMessage());
            return null;
        }
    }

    private function buildRecord9999(int $totalRecords): string
    {
        return sprintf("9999%s", str_pad($totalRecords + 1, 6, '0', STR_PAD_LEFT));
    }

    // ─── Helpers de resolução ─────────────────────────────────────────────────

    private function resolveAssetAndAmount(Transaction $transaction): array
    {
        return match ($transaction->type) {
            'withdrawal', 'fiat_sell' => [
                $transaction->from_asset ?? '',
                (float) ($transaction->from_amount ?? 0),
            ],
            'deposit', 'fiat_buy' => [
                $transaction->to_asset ?? '',
                (float) ($transaction->to_amount ?? 0),
            ],
            default => [
                $transaction->from_asset ?? ($transaction->to_asset ?? ''),
                (float) ($transaction->from_amount ?? $transaction->to_amount ?? 0),
            ],
        };
    }

    private function resolveExchangeCode(Transaction $transaction): string
    {
        if ($transaction->source_type === UserApiKey::class && $transaction->source_id) {
            try {
                $apiKey = UserApiKey::with('exchange')->find($transaction->source_id);
                if ($apiKey?->exchange) {
                    return $this->getExchangeCode($apiKey->exchange->name);
                }
            } catch (\Exception $e) {
                Log::debug("[IN1888] Não foi possível resolver exchange para transação {$transaction->id}");
            }
        }

        if (!empty($transaction->source)) {
            return $this->getExchangeCode($transaction->source);
        }

        return 'OUTROS';
    }

    private function getOperationCode(string $type): string
    {
        return match ($type) {
            'fiat_buy'   => '01',
            'fiat_sell'  => '02',
            'trade'      => '02',
            'convert'    => '02',
            'deposit'    => '03',
            'withdrawal' => '04',
            default      => '99',
        };
    }

    private function getExchangeCode(string $exchange): string
    {
        $normalized = strtolower(trim($exchange));

        return match ($normalized) {
            'binance'                        => 'BINANCE',
            'coinbase'                       => 'COINBASE',
            'kraken'                         => 'KRAKEN',
            'mercado_bitcoin', 'mercadobitcoin' => 'MERCADO BITCOIN',
            'bitget'                         => 'BITGET',
            'bybit'                          => 'BYBIT',
            'kucoin'                         => 'KUCOIN',
            'okx'                            => 'OKX',
            'gate', 'gateio'                 => 'GATE.IO',
            'huobi', 'htx'                   => 'HTX',
            'bitfinex'                       => 'BITFINEX',
            'bitmex'                         => 'BITMEX',
            'foxbit'                         => 'FOXBIT',
            'novadax'                        => 'NOVADAX',
            'ripio'                          => 'RIPIO',
            default                          => 'OUTROS',
        };
    }

    // ─── Formatação ───────────────────────────────────────────────────────────

    private function formatAmount(float $amount): string
    {
        return str_replace('.', '', number_format(abs($amount), 8, '.', ''));
    }

    private function formatValue(float $value): string
    {
        return str_replace('.', '', number_format(abs($value), 2, '.', ''));
    }

    private function generateFilename(User $user, int $month, int $year): string
    {
        $cpf = preg_replace('/\D/', '', $user->cpf ?? '00000000000');
        return sprintf("IN1888_%s_%04d%02d.txt", $cpf, $year, $month);
    }

    // ─── Utilitários públicos ─────────────────────────────────────────────────

    public function validateFile(string $content): array
    {
        $lines  = explode("\r\n", $content);
        $errors = [];

        if (empty($lines)) return ['Arquivo vazio'];

        if (!str_starts_with($lines[0], '0000'))
            $errors[] = 'Registro de abertura (0000) não encontrado';

        if (count($lines) < 2 || !str_starts_with($lines[1], '0010'))
            $errors[] = 'Registro de identificação (0010) não encontrado';

        if (!str_starts_with(end($lines), '9999'))
            $errors[] = 'Registro de encerramento (9999) não encontrado';

        $operationRecords = 0;
        foreach ($lines as $line) {
            if (str_starts_with($line, '0720')) {
                $operationRecords++;
                if (strlen($line) !== 136)
                    $errors[] = "Registro 0720 com tamanho incorreto: " . strlen($line) . " chars (esperado: 136)";
            }
        }

        if ($operationRecords === 0)
            $errors[] = 'Nenhum registro de operação (0720) encontrado';

        return $errors;
    }

    public function getFileHistory(int $userId): array
    {
        $user    = User::find($userId);
        $userCpf = preg_replace('/\D/', '', $user->cpf ?? '');
        $files   = Storage::disk('local')->files('in1888');

        $history = [];
        foreach (array_filter($files, fn ($f) => str_contains($f, $userCpf)) as $file) {
            $filename = basename($file);
            if (preg_match('/IN1888_\d+_(\d{4})(\d{2})\.txt/', $filename, $m)) {
                $history[] = [
                    'filename'     => $filename,
                    'month'        => (int) $m[2],
                    'year'         => (int) $m[1],
                    'period'       => sprintf('%02d/%04d', $m[2], $m[1]),
                    'size'         => Storage::disk('local')->size($file),
                    'created_at'   => Carbon::createFromTimestamp(Storage::disk('local')->lastModified($file)),
                    'download_url' => route('in1888.download', $filename),
                ];
            }
        }

        usort($history, fn ($a, $b) => ($b['year'] * 100 + $b['month']) - ($a['year'] * 100 + $a['month']));
        return $history;
    }

    public function getComplianceStatus(int $userId): array
    {
        $currentMonth = now()->month;
        $currentYear  = now()->year;
        $status       = [];

        for ($i = 0; $i < 12; $i++) {
            $month = $currentMonth - $i;
            $year  = $currentYear;
            if ($month <= 0) { $month += 12; $year--; }

            $transactions = $this->getMonthlyTransactions($userId, $month, $year);
            $volume       = $transactions->sum('total_brl');
            $required     = $volume > 30000;
            $generated    = $this->hasGeneratedFile($userId, $month, $year);

            $status[] = [
                'month'     => $month,
                'year'      => $year,
                'period'    => sprintf('%02d/%04d', $month, $year),
                'volume'    => $volume,
                'required'  => $required,
                'generated' => $generated,
                'status'    => $required ? ($generated ? 'compliant' : 'pending') : 'not_required',
            ];
        }

        return $status;
    }

    private function hasGeneratedFile(int $userId, int $month, int $year): bool
    {
        $user    = User::find($userId);
        $userCpf = preg_replace('/\D/', '', $user->cpf ?? '');
        return Storage::disk('local')->exists(
            sprintf("in1888/IN1888_%s_%04d%02d.txt", $userCpf, $year, $month)
        );
    }
}
