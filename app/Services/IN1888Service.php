<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\User;
use App\Models\UserApiKey;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Gera o arquivo legado de operações por exchange estrangeira de forma somente leitura.
 *
 * A competência escolhe o regime. O leiaute legado é aceito apenas até 06/2026;
 * competências DeCripto nunca recebem, por engano, arquivo da IN 1888.
 */
class IN1888Service
{
    private const LEGACY_RECORD_LENGTHS = [
        '0110' => 219,
        '0120' => 219,
        '0210' => 240,
        '0410' => 204,
        '0510' => 204,
        '0710' => 204,
        '0720' => 204,
    ];

    public function __construct(private CryptoReportingRuleResolver $ruleResolver)
    {
    }

    /**
     * @param bool $validationOnly Permite download técnico sem obrigatoriedade; não transmite dados.
     */
    public function generateMonthlyFile(int $userId, int $month, int $year, bool $validationOnly = false): array
    {
        $user = User::findOrFail($userId);
        $rule = $this->ruleResolver->resolve($year, $month);
        $ruleContext = $this->ruleResolver->context($year, $month);
        $transactions = $this->getMonthlyTransactions($userId, $month, $year);
        $totalVolume = (float) $transactions->sum('total_brl');
        $isRequired = $this->ruleResolver->isMonthlyDeclarationRequired($totalVolume, $rule);

        if (!$rule->legacy_export_available) {
            return $this->baseResponse($transactions, $totalVolume, $isRequired, $ruleContext, [
                'export_available' => false,
                'validation_available' => false,
                'message' => "A competência {$month}/{$year} é regida por {$rule->obligation_name}. O leiaute legado da IN 1888 não será gerado para este período.",
            ]);
        }

        if (!$isRequired && !$validationOnly) {
            $limit = number_format((float) $rule->monthly_threshold_brl, 2, ',', '.');

            return $this->baseResponse($transactions, $totalVolume, false, $ruleContext, [
                'export_available' => false,
                'validation_available' => $transactions->isNotEmpty(),
                'message' => "Volume mensal não superior a R$ {$limit}. A declaração não é obrigatória; é possível gerar apenas um arquivo de validação técnica.",
            ]);
        }

        if ($transactions->isEmpty()) {
            return $this->baseResponse($transactions, $totalVolume, $isRequired, $ruleContext, [
                'export_available' => false,
                'validation_available' => false,
                'message' => 'Não há transações representáveis nesta competência para gerar o arquivo.',
            ]);
        }

        $build = $this->buildLegacyContent($transactions);
        if (empty($build['lines'])) {
            return $this->baseResponse($transactions, $totalVolume, $isRequired, $ruleContext, [
                'export_available' => false,
                'validation_available' => false,
                'unmapped_transactions' => $build['unmapped'],
                'message' => 'Nenhuma transação possui tipo, origem e valor suficientes para o leiaute legado. Revise as pendências antes de gerar o arquivo.',
            ]);
        }

        $content = implode("\r\n", $build['lines']) . "\r\n";
        $validationErrors = $this->validateFile($content);
        if ($validationErrors !== []) {
            throw new \RuntimeException('O arquivo fiscal não passou na validação interna: ' . implode(' | ', $validationErrors));
        }

        $filename = $this->generateFilename($user, $month, $year, $validationOnly);
        Storage::disk('local')->put("in1888/{$filename}", $content);

        Log::info('[IN1888] Arquivo legado somente leitura gerado.', [
            'user_id' => $userId,
            'competence' => sprintf('%02d/%04d', $month, $year),
            'validation_only' => $validationOnly,
            'records' => count($build['lines']),
            'unmapped_transactions' => count($build['unmapped']),
        ]);

        return $this->baseResponse($transactions, $totalVolume, $isRequired, $ruleContext, [
            'export_available' => !$validationOnly,
            'validation_available' => true,
            'validation_only' => $validationOnly,
            'filename' => $filename,
            'content' => $content,
            'file_path' => storage_path("app/in1888/{$filename}"),
            // O frontend baixa o conteúdo retornado como Blob; não há transmissão automática.
            'download_url' => null,
            'unmapped_transactions' => $build['unmapped'],
            'message' => $validationOnly
                ? 'Arquivo de validação gerado. Não transmita uma competência sem obrigatoriedade.'
                : 'Arquivo legado gerado. Valide no ColetaNac antes de qualquer transmissão.',
        ]);
    }

    public function getMonthlyTransactions(int $userId, int $month, int $year): Collection
    {
        $nationalApiKeyIds = UserApiKey::query()
            ->where('user_id', $userId)
            ->whereHas('exchange', fn ($query) => $query->where('country_code', 'BR'))
            ->pluck('id')
            ->all();

        return Transaction::query()
            ->where('user_id', $userId)
            ->whereYear('date', $year)
            ->whereMonth('date', $month)
            ->whereIn('type', ['trade', 'convert', 'deposit', 'withdrawal', 'fiat_buy', 'fiat_sell'])
            ->where(function ($query) use ($nationalApiKeyIds) {
                $query->where(function ($nested) use ($nationalApiKeyIds) {
                    $nested->where('source_type', UserApiKey::class)->whereNotIn('source_id', $nationalApiKeyIds);
                })->orWhere(function ($nested) {
                    $nested->where('source_type', '!=', UserApiKey::class)->orWhereNull('source_type');
                });
            })
            ->orderBy('date')
            ->get();
    }

    /** @return array{lines: array<int, string>, unmapped: array<int, array<string, mixed>>} */
    private function buildLegacyContent(Collection $transactions): array
    {
        $lines = [];
        $unmapped = [];

        foreach ($transactions as $transaction) {
            try {
                $record = $this->buildLegacyRecord($transaction);
                if ($record === null) {
                    $unmapped[] = $this->unmapped($transaction, 'Tipo de operação não representável no leiaute legado.');
                    continue;
                }
                $lines[] = $record;
            } catch (\Throwable $exception) {
                Log::warning('[IN1888] Transação não incluída no arquivo legado.', [
                    'transaction_id' => $transaction->id,
                    'reason' => $exception->getMessage(),
                ]);
                $unmapped[] = $this->unmapped($transaction, $exception->getMessage());
            }
        }

        return compact('lines', 'unmapped');
    }

    private function buildLegacyRecord(Transaction $transaction): ?string
    {
        $exchange = $this->resolveExchangeMetadata($transaction);
        $date = $this->date($transaction)->format('dmY');
        $fee = $this->number((float) ($transaction->fee_brl ?? 0), 10, 2);
        $value = $this->requiredNumber((float) ($transaction->total_brl ?? 0), 15, 2, 'Valor fiscal em BRL ausente');

        return match ($transaction->type) {
            'fiat_buy' => $this->fixed('0110', [
                '0110', $date, $this->text('I', 4), $value, $fee,
                $this->text($transaction->to_asset, 10), $this->requiredNumber((float) $transaction->to_amount, 26, 10, 'Quantidade recebida ausente'),
                $exchange['name'], $exchange['url'], $exchange['country'],
            ]),
            'fiat_sell' => $this->fixed('0120', [
                '0120', $date, $this->text('I', 4), $value, $fee,
                $this->text($transaction->from_asset, 10), $this->requiredNumber((float) $transaction->from_amount, 26, 10, 'Quantidade enviada ausente'),
                $exchange['name'], $exchange['url'], $exchange['country'],
            ]),
            'trade', 'convert' => $this->fixed('0210', [
                '0210', $date, $this->text('II', 4), $fee,
                $this->text($transaction->to_asset, 10), $this->requiredNumber((float) $transaction->to_amount, 26, 10, 'Quantidade recebida ausente'),
                $this->text($transaction->from_asset, 10), $this->requiredNumber((float) $transaction->from_amount, 26, 10, 'Quantidade enviada ausente'),
                $exchange['name'], $exchange['url'], $exchange['country'],
            ]),
            'deposit' => $this->fixed('0410', [
                '0410', $date, $this->text('IV', 4), $fee,
                $this->text($transaction->to_asset, 10), $this->requiredNumber((float) $transaction->to_amount, 26, 10, 'Quantidade recebida ausente'),
                $exchange['name'], $exchange['url'], $exchange['country'],
            ]),
            'withdrawal' => $this->fixed('0510', [
                '0510', $date, $this->text('V', 4), $fee,
                $this->text($transaction->from_asset, 10), $this->requiredNumber((float) $transaction->from_amount, 26, 10, 'Quantidade enviada ausente'),
                $exchange['name'], $exchange['url'], $exchange['country'],
            ]),
            default => null,
        };
    }

    /** @return array{name: string, url: string, country: string} */
    private function resolveExchangeMetadata(Transaction $transaction): array
    {
        $name = null;
        $country = null;
        if ($transaction->source_type === UserApiKey::class && $transaction->source_id) {
            $apiKey = UserApiKey::query()->with('exchange')->find($transaction->source_id);
            $name = $apiKey?->exchange?->name;
            $country = $apiKey?->exchange?->country_code;
        }
        $name ??= is_string($transaction->source ?? null) ? $transaction->source : null;
        $normalized = Str::lower((string) $name);

        if ($normalized === 'binance') {
            return ['name' => $this->text('Binance', 60), 'url' => $this->text('https://www.binance.com', 80), 'country' => $this->text($country ?: 'MT', 2)];
        }

        throw new \RuntimeException('Origem de exchange estrangeira não identificada ou sem metadados de URL e país.');
    }

    private function fixed(string $record, array $fields): string
    {
        $line = implode('', $fields);
        $expected = self::LEGACY_RECORD_LENGTHS[$record];
        if (strlen($line) !== $expected) {
            throw new \RuntimeException("Registro {$record} com " . strlen($line) . " caracteres; esperado {$expected}.");
        }
        return $line;
    }

    private function text(?string $value, int $length): string
    {
        $value = Str::upper(Str::ascii(trim((string) $value)));
        if ($value === '') {
            throw new \RuntimeException('Campo textual obrigatório não informado.');
        }
        return str_pad(substr($value, 0, $length), $length, ' ');
    }

    private function number(float $value, int $length, int $decimals): string
    {
        return $this->formatNumber(max(0, $value), $length, $decimals);
    }

    private function requiredNumber(float $value, int $length, int $decimals, string $error): string
    {
        if ($value <= 0) {
            throw new \RuntimeException($error . '.');
        }
        return $this->formatNumber($value, $length, $decimals);
    }

    private function formatNumber(float $value, int $length, int $decimals): string
    {
        $digits = str_replace('.', '', number_format($value, $decimals, '.', ''));
        if (strlen($digits) > $length) {
            throw new \RuntimeException("Valor numérico excede {$length} posições.");
        }
        return str_pad($digits, $length, '0', STR_PAD_LEFT);
    }

    private function date(Transaction $transaction): Carbon
    {
        return $transaction->date instanceof Carbon ? $transaction->date : Carbon::parse($transaction->date, 'America/Sao_Paulo');
    }

    private function unmapped(Transaction $transaction, string $reason): array
    {
        return ['id' => $transaction->id, 'type' => $transaction->type, 'reference' => $transaction->reference, 'reason' => $reason];
    }

    private function baseResponse(Collection $transactions, float $totalVolume, bool $required, array $rule, array $extra = []): array
    {
        return array_merge([
            'required' => $required,
            'total_volume' => $totalVolume,
            'transactions_count' => $transactions->count(),
            'rule' => $rule,
        ], $extra);
    }

    public function validateFile(string $content): array
    {
        $lines = array_values(array_filter(preg_split('/\r\n|\n|\r/', trim($content)) ?: []));
        $errors = [];
        if ($lines === []) return ['Arquivo vazio'];

        foreach ($lines as $index => $line) {
            $record = substr($line, 0, 4);
            if (!isset(self::LEGACY_RECORD_LENGTHS[$record])) {
                $errors[] = 'Registro desconhecido na linha ' . ($index + 1) . ': ' . $record;
                continue;
            }
            if (strlen($line) !== self::LEGACY_RECORD_LENGTHS[$record]) {
                $errors[] = "Registro {$record} na linha " . ($index + 1) . ' possui tamanho inválido.';
            }
        }
        return $errors;
    }

    private function generateFilename(User $user, int $month, int $year, bool $validationOnly): string
    {
        $cpf = preg_replace('/\D/', '', $user->cpf ?? '00000000000');
        $prefix = $validationOnly ? 'IN1888_VALIDACAO' : 'IN1888';
        return sprintf('%s_%s_%04d%02d.txt', $prefix, $cpf, $year, $month);
    }
}
