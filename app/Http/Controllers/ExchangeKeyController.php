<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\UserApiKey;
use App\Models\Exchange;
use App\Models\Wallet;
use App\Models\Network;
use Illuminate\Support\Facades\Log;

class ExchangeKeyController extends Controller
{
   public function index()
{
    // Recuperar chaves de exchanges cadastradas pelo usuário autenticado
    $exchangeKeys = auth()->user()->exchangeKeys()
        ->with('exchange:id,name')
        ->get(['id', 'exchange_id', 'api_key'])
        ->map(function ($key) {
            return [
                'id' => $key->id,
                'exchange_id' => $key->exchange_id,
                'api_key' => $key->api_key,
                'exchange_name' => $key->exchange->name ?? null,
            ];
        });

    // Recuperar carteiras com os dados da rede associada
    $wallets = auth()->user()->wallets()
        ->with('network:id,name')
        ->get(['id', 'name', 'network_id', 'address', 'api_key', 'description'])
        ->map(function ($wallet) {
            return [
                'id' => $wallet->id,
                'name' => $wallet->name,
                'network_id' => $wallet->network_id,
                'network_name' => $wallet->network->name ?? null,
                'address' => $wallet->address,
                'api_key' => $wallet->api_key,
                'description' => $wallet->description,
            ];
        });
        $exchanges = Exchange::all(['id', 'name']);
        $networks = Network::select('id', 'name')->get();


    // Log para verificar os dados recuperados
    Log::info('Dados recuperados para o usuário:', [
        'user_id' => auth()->id(),
        'exchange_keys' => $exchangeKeys->toArray(),
        'wallets' => $wallets->toArray()
    ]);

    return inertia('ExchangeKeys/Index', [
        'exchangeKeys' => $exchangeKeys,
        'wallets' => $wallets,
         'networks' => $networks,
         'exchanges' => $exchanges 

    ]);
}


  public function store(Request $request)
{
    if ($request->type === 'exchange') {
        // Busca o ID da exchange com base no nome
        $exchangeId = Exchange::where('name', $request->exchange_name)->value('id');

        if (!$exchangeId) {
            return back()->withErrors(['exchange_name' => 'Exchange não encontrada.']);
        }

        // Adiciona ao request para validar e salvar
        $request->merge([
            'exchange_id' => $exchangeId,
        ]);

        // Validação
        $request->validate([
            'exchange_id' => 'required|exists:exchanges,id',
            'api_key' => 'required|string',
            'api_secret' => 'required|string',
        ]);

        // Criação do registro
        auth()->user()->exchangeKeys()->create([
            'exchange_id' => $request->exchange_id,
            'api_key' => $request->api_key,
            'secret_key' => $request->api_secret,
        ]);

    } elseif ($request->type === 'wallet') {
        // Validação para carteira
        $request->validate([
            'wallet_name' => 'required|string',
            'network_id' => 'required|exists:networks,id',
            'wallet_address' => 'required|string|unique:wallets,address',
            'api_key' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
        ]);

        // Criação da carteira
        auth()->user()->wallets()->create([
            'name' => $request->wallet_name,
            'network_id' => $request->network_id,
            'address' => $request->wallet_address,
            'api_key' => $request->api_key,
            'description' => $request->description,
        ]);
    }

    return redirect()->route('exchanges.keys.index')->with('success', 'Cadastro realizado com sucesso!');
}

public function update($id, Request $request)
{
    if ($request->type === 'exchange') {
        $key = auth()->user()->exchangeKeys()->findOrFail($id);

        $request->validate([
            'api_key' => 'required|string',
            'api_secret' => 'required|string',
        ]);

        $key->update([
            'api_key' => $request->api_key,
            'secret_key' => $request->api_secret,
        ]);

    } elseif ($request->type === 'wallet') {
        $wallet = auth()->user()->wallets()->findOrFail($id);

        $request->validate([
            'wallet_name' => 'required|string',
            'network_id' => 'required|exists:networks,id',
            'wallet_address' => 'required|string|unique:wallets,address',
            'api_key' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
        ]);

        $wallet->update([
            'name' => $request->wallet_name,
            'network' => $request->network,
            'address' => $request->wallet_address,
            'api_key' => $request->api_key,
            'description' => $request->description,
        ]);
    }

    return redirect()->route('exchanges.keys.index')->with('success', 'Atualização realizada com sucesso!');
}



    public function destroy($id, Request $request)
    {
        if ($request->type === 'exchange') {
            $key = auth()->user()->exchangeKeys()->findOrFail($id);
        } elseif ($request->type === 'wallet') {
            $key = auth()->user()->wallets()->findOrFail($id);
        }

        if ($key) {
            $key->delete();
            return response()->json(['message' => 'Registro removido com sucesso.']);
        }

        return response()->json(['error' => 'Registro não encontrado.'], 404);
    }
}
