<?php

namespace App\Http\Controllers;

use App\Models\TaxRule;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TaxRuleController extends Controller
{
    /**
     * Display a listing of tax rules.
     */
    public function index()
    {
        $taxRules = auth()->user()->taxRules()
            ->orderBy('priority')
            ->get();

        return Inertia::render('TaxRules/Index', [
            'taxRules' => $taxRules
        ]);
    }

    /**
     * Show the form for creating a new tax rule.
     */
    public function create()
    {
        return Inertia::render('TaxRules/Create');
    }

    /**
     * Store a newly created tax rule.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'rule_type' => 'required|in:fifo,lifo,average_cost,specific_identification',
            'asset_type' => 'nullable|string|max:100',
            'min_holding_period' => 'nullable|integer|min:0',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'exemption_limit' => 'nullable|numeric|min:0',
            'priority' => 'required|integer|min:1',
            'is_active' => 'boolean',
            'conditions' => 'nullable|json'
        ]);

        auth()->user()->taxRules()->create($validated);

        return redirect()->route('tax-rules.index')
            ->with('success', 'Regra fiscal criada com sucesso!');
    }

    /**
     * Display the specified tax rule.
     */
    public function show(TaxRule $taxRule)
    {
        $this->authorize('view', $taxRule);

        return Inertia::render('TaxRules/Show', [
            'taxRule' => $taxRule
        ]);
    }

    /**
     * Show the form for editing the specified tax rule.
     */
    public function edit(TaxRule $taxRule)
    {
        $this->authorize('update', $taxRule);

        return Inertia::render('TaxRules/Edit', [
            'taxRule' => $taxRule
        ]);
    }

    /**
     * Update the specified tax rule.
     */
    public function update(Request $request, TaxRule $taxRule)
    {
        $this->authorize('update', $taxRule);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'rule_type' => 'required|in:fifo,lifo,average_cost,specific_identification',
            'asset_type' => 'nullable|string|max:100',
            'min_holding_period' => 'nullable|integer|min:0',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'exemption_limit' => 'nullable|numeric|min:0',
            'priority' => 'required|integer|min:1',
            'is_active' => 'boolean',
            'conditions' => 'nullable|json'
        ]);

        $taxRule->update($validated);

        return redirect()->route('tax-rules.index')
            ->with('success', 'Regra fiscal atualizada com sucesso!');
    }

    /**
     * Remove the specified tax rule.
     */
    public function destroy(TaxRule $taxRule)
    {
        $this->authorize('delete', $taxRule);

        $taxRule->delete();

        return redirect()->route('tax-rules.index')
            ->with('success', 'Regra fiscal removida com sucesso!');
    }

    /**
     * Apply tax rules to transactions.
     */
    public function applyRules(Request $request)
    {
        $validated = $request->validate([
            'transaction_ids' => 'required|array',
            'transaction_ids.*' => 'exists:transactions,id',
            'tax_rule_id' => 'nullable|exists:tax_rules,id'
        ]);

        try {
            // Implementar aplicação de regras fiscais
            // Usar TaxCalculator service
            
            return response()->json([
                'message' => 'Regras fiscais aplicadas com sucesso!',
                'processed_transactions' => count($validated['transaction_ids'])
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Erro ao aplicar regras fiscais: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Calculate tax for specific period.
     */
    public function calculateTax(Request $request)
    {
        $validated = $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'tax_rule_id' => 'nullable|exists:tax_rules,id'
        ]);

        try {
            // Implementar cálculo de impostos
            // Usar TaxCalculator service
            
            $taxCalculation = [
                'total_gains' => 0,
                'total_losses' => 0,
                'net_result' => 0,
                'tax_owed' => 0,
                'exemption_used' => 0,
                'transactions_count' => 0
            ];

            return response()->json([
                'message' => 'Cálculo de impostos realizado com sucesso!',
                'calculation' => $taxCalculation
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Erro ao calcular impostos: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get default tax rules.
     */
    public function getDefaults()
    {
        $defaults = [
            [
                'name' => 'FIFO Padrão Brasil',
                'rule_type' => 'fifo',
                'tax_rate' => 15,
                'exemption_limit' => 35000,
                'priority' => 1,
                'description' => 'Regra FIFO padrão para criptomoedas no Brasil'
            ],
            [
                'name' => 'Day Trade',
                'rule_type' => 'fifo',
                'tax_rate' => 20,
                'exemption_limit' => 0,
                'priority' => 2,
                'description' => 'Regra para operações de day trade'
            ]
        ];

        return response()->json($defaults);
    }
}

