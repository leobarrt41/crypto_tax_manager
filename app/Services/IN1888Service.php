<?php

namespace App\Services;

use App\Models\User;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

class IN1888Service
{
    public function generateMonthlyFile($userId, $month, $year)
    {
        $user = User::findOrFail($userId);
        $transactions = $this->getMonthlyTransactions($userId, $month, $year);
        $totalVolume = $transactions->sum('value_brl');
        
        // Verificar se precisa gerar (volume > R$ 30.000)
        if ($totalVolume <= 30000) {
            return [
                'required' => false,
                'message' => 'Volume mensal inferior a R$ 30.000. IN 1888 não é obrigatória.',
                'total_volume' => $totalVolume,
                'transactions_count' => $transactions->count()
            ];
        }
        
        $content = $this->buildFileContent($transactions, $user, $month, $year);
        $filename = $this->generateFilename($user, $month, $year);
        
        Storage::disk('local')->put("in1888/{$filename}", $content);
        
        return [
            'required' => true,
            'filename' => $filename,
            'content' => $content,
            'total_volume' => $totalVolume,
            'transactions_count' => $transactions->count(),
            'file_path' => storage_path("app/in1888/{$filename}"),
            'download_url' => route('in1888.download', $filename)
        ];
    }
    
    private function getMonthlyTransactions($userId, $month, $year)
    {
        return Transaction::byUser($userId)
            ->byMonth($month, $year)
            ->orderBy('date')
            ->get();
    }
    
    private function buildFileContent($transactions, $user, $month, $year)
    {
        $lines = [];
        
        // Registro 0000 - Abertura do arquivo
        $lines[] = $this->buildRecord0000($user, $month, $year);
        
        // Registro 0010 - Identificação da pessoa física
        $lines[] = $this->buildRecord0010($user);
        
        // Registros 0720 - Operações com criptoativos
        foreach ($transactions as $transaction) {
            $lines[] = $this->buildRecord0720($transaction);
        }
        
        // Registro 9999 - Encerramento do arquivo
        $lines[] = $this->buildRecord9999(count($lines));
        
        return implode("\r\n", $lines);
    }
    
    private function buildRecord0000($user, $month, $year)
    {
        return sprintf(
            "0000%s%02d%04d%s%s",
            str_pad(preg_replace('/\D/', '', $user->cpf), 11, '0', STR_PAD_LEFT),
            $month,
            $year,
            str_pad('', 8, ' '), // Espaços reservados
            'IN1888'
        );
    }
    
    private function buildRecord0010($user)
    {
        return sprintf(
            "0010%s%s%s",
            str_pad(preg_replace('/\D/', '', $user->cpf), 11, '0', STR_PAD_LEFT),
            str_pad(mb_strtoupper($user->name), 60, ' ', STR_PAD_RIGHT),
            str_pad('', 29, ' ') // Espaços reservados
        );
    }
    
    private function buildRecord0720($transaction)
    {
        return sprintf(
            "0720%s%s%s%s%s%s%s",
            $transaction->date->format('dmY'),
            str_pad($this->getOperationCode($transaction->type), 2, '0', STR_PAD_LEFT),
            str_pad(mb_strtoupper($transaction->crypto_asset), 10, ' ', STR_PAD_RIGHT),
            str_pad($this->formatAmount($transaction->amount), 18, '0', STR_PAD_LEFT),
            str_pad($this->formatPrice($transaction->price), 18, '0', STR_PAD_LEFT),
            str_pad($this->formatValue($transaction->value_brl), 18, '0', STR_PAD_LEFT),
            str_pad(mb_strtoupper($this->getExchangeCode($transaction->exchange)), 60, ' ', STR_PAD_RIGHT)
        );
    }
    
    private function buildRecord9999($totalRecords)
    {
        return sprintf(
            "9999%s",
            str_pad($totalRecords + 1, 6, '0', STR_PAD_LEFT) // +1 para incluir este registro
        );
    }
    
    private function getOperationCode($type)
    {
        $codes = [
            'buy' => '01',
            'sell' => '02',
            'convert_from' => '02',
            'convert_to' => '01',
            'transfer_in' => '03',
            'transfer_out' => '04',
            'deposit' => '03',
            'withdrawal' => '04'
        ];
        
        return $codes[$type] ?? '99';
    }
    
    private function getExchangeCode($exchange)
    {
        $codes = [
            'binance' => 'BINANCE',
            'coinbase' => 'COINBASE',
            'kraken' => 'KRAKEN',
            'mercado_bitcoin' => 'MERCADO BITCOIN',
            'bitget' => 'BITGET',
            'bybit' => 'BYBIT'
        ];
        
        return $codes[$exchange] ?? 'OUTROS';
    }
    
    private function formatAmount($amount)
    {
        // Formato: 18 posições, 8 decimais, sem ponto decimal
        return str_replace('.', '', number_format($amount, 8, '.', ''));
    }
    
    private function formatPrice($price)
    {
        // Formato: 18 posições, 2 decimais, sem ponto decimal
        return str_replace('.', '', number_format($price, 2, '.', ''));
    }
    
    private function formatValue($value)
    {
        // Formato: 18 posições, 2 decimais, sem ponto decimal
        return str_replace('.', '', number_format($value, 2, '.', ''));
    }
    
    private function generateFilename($user, $month, $year)
    {
        $cpf = preg_replace('/\D/', '', $user->cpf);
        return sprintf(
            "IN1888_%s_%04d%02d.txt",
            $cpf,
            $year,
            $month
        );
    }
    
    public function validateFile($content)
    {
        $lines = explode("\r\n", $content);
        $errors = [];
        
        // Validar estrutura básica
        if (empty($lines)) {
            $errors[] = 'Arquivo vazio';
            return $errors;
        }
        
        // Validar registro de abertura
        if (!str_starts_with($lines[0], '0000')) {
            $errors[] = 'Registro de abertura (0000) não encontrado';
        }
        
        // Validar registro de identificação
        if (count($lines) < 2 || !str_starts_with($lines[1], '0010')) {
            $errors[] = 'Registro de identificação (0010) não encontrado';
        }
        
        // Validar registro de encerramento
        $lastLine = end($lines);
        if (!str_starts_with($lastLine, '9999')) {
            $errors[] = 'Registro de encerramento (9999) não encontrado';
        }
        
        // Validar registros de operações
        $operationRecords = 0;
        foreach ($lines as $line) {
            if (str_starts_with($line, '0720')) {
                $operationRecords++;
                
                // Validar tamanho da linha
                if (strlen($line) !== 136) {
                    $errors[] = "Registro 0720 com tamanho incorreto: " . strlen($line) . " caracteres";
                }
            }
        }
        
        if ($operationRecords === 0) {
            $errors[] = 'Nenhum registro de operação (0720) encontrado';
        }
        
        return $errors;
    }
    
    public function getFileHistory($userId)
    {
        $files = Storage::disk('local')->files('in1888');
        $userCpf = preg_replace('/\D/', '', User::find($userId)->cpf);
        
        $userFiles = array_filter($files, function($file) use ($userCpf) {
            return str_contains($file, $userCpf);
        });
        
        $history = [];
        foreach ($userFiles as $file) {
            $filename = basename($file);
            $fileInfo = pathinfo($filename);
            
            // Extrair mês e ano do nome do arquivo
            if (preg_match('/IN1888_\d+_(\d{4})(\d{2})\.txt/', $filename, $matches)) {
                $year = $matches[1];
                $month = $matches[2];
                
                $history[] = [
                    'filename' => $filename,
                    'month' => (int)$month,
                    'year' => (int)$year,
                    'period' => sprintf('%02d/%04d', $month, $year),
                    'size' => Storage::disk('local')->size($file),
                    'created_at' => Carbon::createFromTimestamp(Storage::disk('local')->lastModified($file)),
                    'download_url' => route('in1888.download', $filename)
                ];
            }
        }
        
        // Ordenar por data decrescente
        usort($history, function($a, $b) {
            return ($b['year'] * 100 + $b['month']) - ($a['year'] * 100 + $a['month']);
        });
        
        return $history;
    }
    
    public function getComplianceStatus($userId)
    {
        $user = User::find($userId);
        $currentMonth = now()->month;
        $currentYear = now()->year;
        
        // Verificar últimos 12 meses
        $status = [];
        for ($i = 0; $i < 12; $i++) {
            $month = $currentMonth - $i;
            $year = $currentYear;
            
            if ($month <= 0) {
                $month += 12;
                $year--;
            }
            
            $volume = $user->getMonthlyTransactionVolume($month, $year);
            $required = $volume > 30000;
            $generated = $this->hasGeneratedFile($userId, $month, $year);
            
            $status[] = [
                'month' => $month,
                'year' => $year,
                'period' => sprintf('%02d/%04d', $month, $year),
                'volume' => $volume,
                'required' => $required,
                'generated' => $generated,
                'status' => $required ? ($generated ? 'compliant' : 'pending') : 'not_required'
            ];
        }
        
        return $status;
    }
    
    private function hasGeneratedFile($userId, $month, $year)
    {
        $userCpf = preg_replace('/\D/', '', User::find($userId)->cpf);
        $filename = sprintf("in1888/IN1888_%s_%04d%02d.txt", $userCpf, $year, $month);
        
        return Storage::disk('local')->exists($filename);
    }
}

