<template>
  <AppLayout :title="pageTitle">
    <div class="py-12">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <!-- Header -->
        <div class="overflow-hidden shadow-xl sm:rounded-xl mb-6 border border-indigo-200 bg-gradient-to-r from-indigo-50 via-blue-50 to-emerald-50 dark:border-indigo-500/40 dark:from-indigo-950/80 dark:via-blue-950/60 dark:to-emerald-950/50">
          <div class="p-6 border-b border-indigo-200/80 dark:border-indigo-500/30">
            <div class="flex justify-between items-center">
              <div>
                <p class="mb-1 text-xs font-bold uppercase tracking-widest text-indigo-600 dark:text-indigo-300">Relatórios fiscais</p>
                <h2 class="text-2xl font-bold text-slate-900 dark:text-white">{{ obligationName }}</h2>
                <p class="text-slate-600 mt-1 dark:text-slate-200">Consulte a regra da competência e gere somente o leiaute fiscal aplicável.</p>
              </div>
              <div class="flex space-x-3">
                <Link :href="route('reports.index')" 
                      class="border border-slate-300 bg-white/80 hover:bg-white text-slate-700 px-4 py-2 rounded-lg font-medium transition-colors dark:border-slate-500 dark:bg-slate-800/80 dark:text-white dark:hover:bg-slate-700">
                  <svg class="w-5 h-5 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                  </svg>
                  Voltar
                </Link>
                <button
                  @click="generateIN1888"
                  :disabled="loading || !form.year || !form.month || !canGenerateLegacy"
                  class="bg-emerald-600 hover:bg-emerald-500 disabled:bg-slate-400 disabled:text-slate-100 text-white px-4 py-2 rounded-lg font-medium shadow-sm transition-colors dark:disabled:bg-slate-700 dark:disabled:text-slate-400"
                >
                  <svg v-if="loading" class="w-5 h-5 inline mr-2 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                  </svg>
                  <svg v-else class="w-5 h-5 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                  </svg>
                  {{ loading ? 'Gerando...' : 'Gerar arquivo legado' }}
                </button>
              </div>
            </div>
          </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
          <!-- Formulário de Configuração -->
          <div class="lg:col-span-1">
            <div class="bg-white rounded-xl border-t-4 border-indigo-500 shadow p-6 dark:bg-slate-900 dark:border-indigo-400">
              <h3 class="text-lg font-semibold text-indigo-800 mb-4 dark:text-indigo-200">Configurações da declaração</h3>
              
              <form @submit.prevent="generateIN1888" class="space-y-4">
                <!-- Período -->
                <div class="grid grid-cols-2 gap-3">
                  <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Mês *</label>
                    <select
                      v-model="form.month"
                      required
                      class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500"
                    >
                      <option value="">Mês</option>
                      <option v-for="(month, index) in months" :key="index" :value="index + 1">{{ month }}</option>
                    </select>
                  </div>
                  <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Ano *</label>
                    <select
                      v-model="form.year"
                      required
                      class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500"
                    >
                      <option value="">Ano</option>
                      <option v-for="year in availableYears" :key="year" :value="year">{{ year }}</option>
                    </select>
                  </div>
                </div>

                <!-- Dados do Declarante -->
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-2">CPF do Declarante *</label>
                  <input
                    v-model="form.declarant_cpf"
                    type="text"
                    required
                    placeholder="000.000.000-00"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500"
                  />
                </div>

                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-2">Nome Completo *</label>
                  <input
                    v-model="form.declarant_name"
                    type="text"
                    required
                    placeholder="Nome completo do declarante"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500"
                  />
                </div>

                <!-- Tipo de Arquivo -->
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-2">Tipo de Arquivo</label>
                  <div class="space-y-2">
                    <label class="flex items-center">
                      <input
                        v-model="form.file_type"
                        type="radio"
                        value="original"
                        class="mr-2 text-green-600 focus:ring-green-500"
                      />
                      <span class="text-sm">Original</span>
                    </label>
                    <label class="flex items-center">
                      <input
                        v-model="form.file_type"
                        type="radio"
                        value="retificadora"
                        class="mr-2 text-green-600 focus:ring-green-500"
                      />
                      <span class="text-sm">Retificadora</span>
                    </label>
                  </div>
                </div>

                <!-- Incluir Operações -->
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-2">Incluir Operações</label>
                  <div class="space-y-2">
                    <label class="flex items-center">
                      <input
                        v-model="form.include_purchases"
                        type="checkbox"
                        class="mr-2 text-green-600 focus:ring-green-500"
                      />
                      <span class="text-sm">Compras</span>
                    </label>
                    <label class="flex items-center">
                      <input
                        v-model="form.include_sales"
                        type="checkbox"
                        class="mr-2 text-green-600 focus:ring-green-500"
                      />
                      <span class="text-sm">Vendas</span>
                    </label>
                    <label class="flex items-center">
                      <input
                        v-model="form.include_transfers"
                        type="checkbox"
                        class="mr-2 text-green-600 focus:ring-green-500"
                      />
                      <span class="text-sm">Transferências</span>
                    </label>
                    <label class="flex items-center">
                      <input
                        v-model="form.include_mining"
                        type="checkbox"
                        class="mr-2 text-green-600 focus:ring-green-500"
                      />
                      <span class="text-sm">Mineração</span>
                    </label>
                  </div>
                </div>

                <!-- Observações -->
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-2">Observações</label>
                  <textarea
                    v-model="form.notes"
                    rows="3"
                    placeholder="Observações adicionais..."
                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500"
                  ></textarea>
                </div>
              </form>
            </div>

            <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 mt-6 shadow-sm dark:border-blue-500/40 dark:bg-blue-950/50">
              <div class="flex">
                <svg class="w-5 h-5 text-blue-500 mr-2 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                  <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                </svg>
                <div>
                  <h4 class="text-sm font-medium text-blue-800">Regra aplicável à competência</h4>
                  <p v-if="selectedCompetenceLabel" class="text-xs text-blue-600 mt-1">Competência selecionada: <strong>{{ selectedCompetenceLabel }}</strong></p>
                  <div v-if="currentRule" class="text-sm text-blue-700 mt-1 space-y-1">
                    <p><strong>{{ currentRule.obligation_name }}</strong> — {{ currentRule.legal_reference || 'regra fiscal cadastrada' }}.</p>
                    <p>Obrigatória quando o volume mensal for superior a <strong>{{ formatCurrency(currentRule.monthly_threshold_brl) }}</strong>.</p>
                    <p>Prazo: {{ currentRule.deadline_rule }}.</p>
                    <p v-if="!legacyExportAvailable" class="font-medium text-amber-700">A competência usa DeCripto. O arquivo legado da IN 1888 fica bloqueado até a implementação do leiaute oficial.</p>
                  </div>
                  <p v-else class="text-sm text-blue-700 mt-1">Selecione a competência para carregar a regra fiscal aplicável.</p>
                </div>
              </div>
            </div>
          </div>

          <!-- Prévia e Resultados -->
          <div class="lg:col-span-2">
            <!-- Estatísticas do Período -->
            <div v-if="form.year && form.month" class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
              <StatCard
                title="Operações no Período"
                :value="monthStats.total_operations"
                icon="document-text"
                color="blue"
              />
              <StatCard
                title="Volume Total"
                :value="monthStats.total_volume"
                format="currency"
                icon="currency-dollar"
                color="green"
              />
              <StatCard
                title="Obrigatoriedade"
                :value="monthStats.is_required ? 'Obrigatória' : 'Não Obrigatória'"
                format="text"
                icon="shield-check"
                color="purple"
              />
              <StatCard
                title="Status"
                :value="monthStats.status"
                format="text"
                icon="check-circle"
                color="yellow"
              />
            </div>

            <!-- Prévia do Arquivo -->
            <div class="bg-white rounded-xl border-t-4 border-emerald-500 shadow dark:bg-slate-900 dark:border-emerald-400">
              <div class="p-6 border-b border-gray-200 dark:border-slate-700">
                <h3 class="text-lg font-semibold text-emerald-800 dark:text-emerald-200">Prévia do arquivo {{ obligationName }}</h3>
              </div>

              <div v-if="!form.year || !form.month" class="p-10 text-center bg-gradient-to-b from-emerald-50/60 to-transparent dark:from-emerald-950/30">
                <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-emerald-100 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-300">
                <svg class="w-9 h-9" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                </div>
                <h4 class="text-lg font-semibold text-slate-900 mb-2 dark:text-white">Selecione o período</h4>
                <p class="text-slate-500 dark:text-slate-300">Escolha o mês e ano para consultar a obrigação e o leiaute aplicável.</p>
              </div>

              <div v-else-if="loading" class="p-8 text-center">
                <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-green-600 mx-auto"></div>
                <p class="text-gray-500 mt-2">Processando a competência fiscal...</p>
              </div>

              <div v-else class="p-6">
                <!-- Informações do Arquivo -->
                <div class="mb-6">
                  <h4 class="text-md font-medium text-gray-900 mb-3">Informações do Arquivo</h4>
                  <div class="bg-gray-50 rounded-lg p-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                      <div>
                        <span class="font-medium">Período:</span> {{ getMonthName(form.month) }}/{{ form.year }}
                      </div>
                      <div>
                        <span class="font-medium">Tipo:</span> {{ form.file_type === 'original' ? 'Original' : 'Retificadora' }}
                      </div>
                      <div>
                        <span class="font-medium">Declarante:</span> {{ form.declarant_name }}
                      </div>
                      <div>
                        <span class="font-medium">CPF:</span> {{ form.declarant_cpf }}
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Resumo das Operações -->
                <div class="mb-6">
                  <h4 class="text-md font-medium text-gray-900 mb-3">Resumo das Operações</h4>
                  <div class="space-y-3">
                    <div v-if="form.include_purchases" class="flex justify-between items-center py-2 border-b border-gray-200">
                      <span class="text-gray-600">Compras:</span>
                      <span class="font-medium">{{ monthStats.purchases_count }} operações - {{ formatCurrency(monthStats.purchases_volume) }}</span>
                    </div>
                    <div v-if="form.include_sales" class="flex justify-between items-center py-2 border-b border-gray-200">
                      <span class="text-gray-600">Vendas:</span>
                      <span class="font-medium">{{ monthStats.sales_count }} operações - {{ formatCurrency(monthStats.sales_volume) }}</span>
                    </div>
                    <div v-if="form.include_transfers" class="flex justify-between items-center py-2 border-b border-gray-200">
                      <span class="text-gray-600">Transferências:</span>
                      <span class="font-medium">{{ monthStats.transfers_count }} operações</span>
                    </div>
                    <div v-if="form.include_mining" class="flex justify-between items-center py-2 border-b border-gray-200">
                      <span class="text-gray-600">Mineração:</span>
                      <span class="font-medium">{{ monthStats.mining_count }} operações - {{ formatCurrency(monthStats.mining_volume) }}</span>
                    </div>
                    <div class="flex justify-between items-center py-2 font-medium">
                      <span class="text-gray-900">Total:</span>
                      <span class="text-blue-600">{{ monthStats.total_operations }} operações - {{ formatCurrency(monthStats.total_volume) }}</span>
                    </div>
                  </div>
                </div>

                <!-- Principais Criptoativos -->
                <div class="mb-6">
                  <h4 class="text-md font-medium text-gray-900 mb-3">Principais Criptoativos</h4>
                  <div class="space-y-2">
                    <div v-for="asset in monthStats.top_assets" :key="asset.symbol" 
                         class="flex justify-between items-center py-2 px-3 bg-gray-50 rounded">
                      <span class="font-medium">{{ asset.symbol }}</span>
                      <div class="text-right">
                        <div class="text-sm font-medium">{{ asset.operations }} operações</div>
                        <div class="text-xs text-gray-600">{{ formatCurrency(asset.volume) }}</div>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Validações -->
                <div class="mb-6">
                  <h4 class="text-md font-medium text-gray-900 mb-3">Validações</h4>
                  <div class="space-y-2">
                    <div class="flex items-center">
                      <svg class="w-4 h-4 text-green-500 mr-2" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                      </svg>
                      <span class="text-sm">Dados do declarante válidos</span>
                    </div>
                    <div class="flex items-center">
                      <svg class="w-4 h-4 text-green-500 mr-2" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                      </svg>
                      <span class="text-sm">Período válido</span>
                    </div>
                    <div class="flex items-center">
                      <svg :class="monthStats.is_required ? 'text-yellow-500' : 'text-green-500'" class="w-4 h-4 mr-2" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                      </svg>
                      <span class="text-sm">
                        {{ monthStats.is_required ? `Declaração obrigatória (volume superior a ${formatCurrency(currentRule?.monthly_threshold_brl)})` : `Declaração não obrigatória para o limite de ${formatCurrency(currentRule?.monthly_threshold_brl)}` }}
                      </span>
                    </div>
                  </div>
                </div>

                <!-- Ações -->
                <div class="flex space-x-3 pt-4 border-t border-gray-200">
                  <button
                    @click="generateIN1888()"
                    :disabled="loading || !canGenerateLegacy"
                    class="flex-1 bg-green-600 hover:bg-green-700 disabled:bg-gray-400 text-white px-4 py-2 rounded-lg font-medium transition-colors"
                  >
                    <svg class="w-4 h-4 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    Gerar arquivo da competência
                  </button>
                  <button
                    @click="generateIN1888(true)"
                    :disabled="loading || !canGenerateValidation"
                    class="flex-1 bg-amber-600 hover:bg-amber-700 disabled:bg-gray-400 text-white px-4 py-2 rounded-lg font-medium transition-colors"
                  >
                    Gerar arquivo de validação — não transmitir
                  </button>
                </div>
                <p v-if="canGenerateValidation && !monthStats.is_required" class="mt-3 text-xs text-amber-700">
                  Este arquivo serve apenas para validar o leiaute no ColetaNac. A competência selecionada não foi classificada como obrigatória.
                </p>
              </div>
            </div>
          </div>
        </div>

        <!-- ================================================================
             Seção: Obrigatoriedade mensal (ano a ano)
             Apenas exchanges ESTRANGEIRAS entram no cálculo.
             Exchanges nacionais (BR) não geram obrigação de IN 1888.
        ================================================================ -->
        <div class="mt-8">
          <div class="bg-white rounded-lg shadow">
            <div class="p-6 border-b border-gray-200 flex flex-wrap items-center justify-between gap-3">
              <div>
                <h3 class="text-lg font-medium text-gray-900">Obrigatoriedade mensal por competência</h3>
                <p class="text-sm text-gray-500 mt-1">
                  Considera movimentações em <strong>exchanges estrangeiras</strong> e carteiras próprias, aplicando o regime vigente em cada mês.
                </p>
              </div>
              <div class="flex items-center gap-3">
                <button
                  @click="loadAnnualStatus"
                  :disabled="annualLoading || !selectedYearForStatus"
                  class="inline-flex items-center px-3 py-1.5 border border-blue-600 shadow-sm text-sm font-medium rounded-md text-blue-700 bg-white hover:bg-blue-50 disabled:opacity-50"
                >
                  <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                  </svg>
                  Calcular obrigatoriedade
                </button>
                <button
                  @click="exportAnnualCsv"
                  :disabled="annualLoading || !annualData.months.length || !selectedYearForStatus"
                  class="inline-flex items-center px-3 py-1.5 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 disabled:opacity-50"
                >
                  <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                  </svg>
                  Exportar CSV
                </button>
              </div>
            </div>

            <!-- Resumo anual -->
            <div v-if="annualData.summary" class="grid grid-cols-3 divide-x divide-gray-200 border-b border-gray-200">
              <div class="p-4 text-center">
                <div class="text-2xl font-bold text-red-600">{{ annualData.summary.required }}</div>
                <div class="text-xs text-gray-500 mt-1">Meses obrigatórios</div>
              </div>
              <div class="p-4 text-center">
                <div class="text-2xl font-bold text-green-600">{{ annualData.summary.not_required }}</div>
                <div class="text-xs text-gray-500 mt-1">Meses não obrigatórios</div>
              </div>
              <div class="p-4 text-center">
                <div class="text-2xl font-bold text-gray-400">{{ annualData.summary.no_data }}</div>
                <div class="text-xs text-gray-500 mt-1">Meses sem dados</div>
              </div>
            </div>

            <!-- Tabela dos 12 meses -->
            <div class="p-6">
              <div v-if="annualLoading" class="flex justify-center py-8">
                <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-green-600"></div>
              </div>

              <div v-else-if="!annualData.months.length" class="text-center py-8 text-gray-400">
                Nenhum dado encontrado para {{ selectedYearForStatus }}.
              </div>

              <table v-else class="min-w-full divide-y divide-gray-200">
                <thead>
                  <tr class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    <th class="pb-3 pr-4">Mês</th>
                    <th class="pb-3 pr-4 text-right">Volume (R$)</th>
                    <th class="pb-3 pr-4 text-right">Transações</th>
                    <th class="pb-3 pr-4">Obrigação</th>
                    <th class="pb-3">Status</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                  <tr v-for="row in annualData.months" :key="row.month" class="hover:bg-gray-50">
                    <td class="py-3 pr-4 text-sm font-medium text-gray-900">{{ row.month_label }}</td>
                    <td class="py-3 pr-4 text-sm text-gray-700 text-right">
                      {{ row.transactions_count > 0 ? formatCurrency(row.volume_brl) : '—' }}
                    </td>
                    <td class="py-3 pr-4 text-sm text-gray-500 text-right">{{ row.transactions_count }}</td>
                    <td class="py-3 pr-4 text-sm text-gray-700">
                      <div>{{ row.rule?.obligation_name || '—' }}</div>
                      <div class="text-xs text-gray-400">Limite: {{ formatCurrency(row.rule?.monthly_threshold_brl) }}</div>
                    </td>
                    <td class="py-3">
                      <span
                        class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
                        :class="annualStatusBadge(row.status)"
                      >
                        {{ row.status_label }}
                      </span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import StatCard from '@/Components/StatCard.vue'

const props = defineProps({
  availableYears: Array,
  declarantInfo: Object
})

// ── Status anual de obrigatoriedade ────────────────────────────────────────
const currentYear = new Date().getFullYear()
const annualLoading = ref(false)
const annualData = ref({ year: currentYear, months: [], summary: null })
const currentRule = ref(null)
const legacyExportAvailable = computed(() => currentRule.value?.legacy_export_available === true)
const canGenerateLegacy = computed(() => legacyExportAvailable.value && monthStats.value.is_required === true)
const canGenerateValidation = computed(() => legacyExportAvailable.value && Number(monthStats.value.total_operations) > 0)
const obligationName = computed(() => currentRule.value?.obligation_name || 'Obrigação de criptoativos')
const pageTitle = computed(() => `Declaração de criptoativos — ${obligationName.value}`)
const selectedYearForStatus = computed(() => Number(form.value.year) || currentYear)
const selectedCompetenceLabel = computed(() => {
  const year = Number(form.value.year)
  const month = Number(form.value.month)
  return year > 0 && month >= 1 && month <= 12 ? `${getMonthName(month)}/${year}` : ''
})
let competenceRequestVersion = 0

const loadAnnualStatus = async () => {
  const yearToLoad = selectedYearForStatus.value
  if (!yearToLoad) return

  annualLoading.value = true
  try {
    const res  = await fetch(`/tax-reports/in1888-status/annual?year=${yearToLoad}`, {
      headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
    })
    const data = await res.json()
    annualData.value = data
  } catch (err) {
    console.error('Erro ao carregar status anual IN 1888:', err)
  } finally {
    annualLoading.value = false
  }
}

const exportAnnualCsv = () => {
  window.location.href = `/tax-reports/in1888-status/export-csv?year=${selectedYearForStatus.value}`
}

const annualStatusBadge = (status) => {
  const map = {
    required:     'bg-red-100 text-red-800',
    not_required: 'bg-green-100 text-green-800',
    no_data:      'bg-gray-100 text-gray-600',
  }
  return map[status] ?? 'bg-gray-100 text-gray-600'
}

const loading = ref(false)
const months = [
  'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho',
  'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'
]

const form = ref({
  month: '',
  year: String(currentYear),
  declarant_cpf: props.declarantInfo?.cpf || '',
  declarant_name: props.declarantInfo?.name || '',
  file_type: 'original',
  include_purchases: true,
  include_sales: true,
  include_transfers: true,
  include_mining: false,
  notes: ''
})

const monthStats = ref({
  total_operations: 0,
  total_volume: 0,
  is_required: false,
  status: 'Não Gerado',
  purchases_count: 0,
  purchases_volume: 0,
  sales_count: 0,
  sales_volume: 0,
  transfers_count: 0,
  mining_count: 0,
  mining_volume: 0,
  top_assets: []
})

const formatCurrency = (value) => {
  const numericValue = Number(value)
  if (!Number.isFinite(numericValue)) return '—'

  return new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL'
  }).format(numericValue)
}

const getMonthName = (monthNumber) => {
  return months[monthNumber - 1] || ''
}

const generateIN1888 = async (validationOnly = false) => {
  if (!form.value.year || !form.value.month) {
    alert('Por favor, selecione o período (mês e ano).')
    return
  }

  if (!legacyExportAvailable.value) {
    alert('Esta competência é regida pela DeCripto. O arquivo legado da IN 1888 não pode ser gerado.')
    return
  }

  if (!validationOnly && !canGenerateLegacy.value) {
    alert('A geração oficial só é liberada quando a competência for obrigatória. Para teste técnico, use o arquivo de validação.')
    return
  }

  if (validationOnly && !canGenerateValidation.value) {
    alert('Não há operações representáveis nesta competência para gerar um arquivo de validação.')
    return
  }

  loading.value = true
  try {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
    const response = await fetch('/reports/in1888/generate', {
      method: 'POST',
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {})
      },
      body: JSON.stringify({
        month: Number(form.value.month),
        year: Number(form.value.year),
        validation_only: validationOnly,
      })
    })
    const payload = await response.json()

    if (!response.ok || !payload.report?.content) {
      alert(payload.message || 'Não foi possível gerar o arquivo para esta competência.')
      return
    }

    const blob = new Blob([payload.report.content], { type: 'text/plain;charset=utf-8' })
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = payload.report.filename || `IN1888_${form.value.year}${String(form.value.month).padStart(2, '0')}.txt`
    link.click()
    URL.revokeObjectURL(url)

    if (validationOnly) {
      alert('Arquivo de validação baixado. Não transmita se a competência não for obrigatória.')
    }
  } catch (error) {
    console.error('Erro ao gerar arquivo fiscal:', error)
    alert('Não foi possível processar a declaração. Tente novamente.')
  } finally {
    loading.value = false
  }
}


const resetCompetenceData = () => {
  currentRule.value = null
  monthStats.value = {
    ...monthStats.value,
    total_operations: 0,
    total_volume: 0,
    is_required: false,
    status: 'Selecione a competência',
  }
}

// A regra deve acompanhar a competência escolhida, inclusive na transição de 2026.
watch([() => form.value.year, () => form.value.month], async ([newYear, newMonth]) => {
  const year = Number(newYear)
  const month = Number(newMonth)
  const requestVersion = ++competenceRequestVersion

  if (!year || month < 1 || month > 12) {
    resetCompetenceData()
    return
  }

  currentRule.value = null
  monthStats.value = {
    ...monthStats.value,
    total_operations: 0,
    total_volume: 0,
    is_required: false,
    status: 'Carregando regra...',
  }

  try {
    const response = await fetch(`/tax-reports/in1888-status/monthly?year=${year}&month=${month}`, {
      headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
    })

    if (!response.ok) {
      throw new Error(`HTTP ${response.status}`)
    }

    const data = await response.json()
    if (requestVersion !== competenceRequestVersion) return

    currentRule.value = data.rule ?? null
    monthStats.value = {
      ...monthStats.value,
      total_operations: data.transactions_count ?? 0,
      total_volume: data.volume_brl ?? 0,
      is_required: data.status === 'required',
      status: data.status_label ?? 'Sem dados',
    }
  } catch (error) {
    if (requestVersion !== competenceRequestVersion) return

    console.error('Erro ao carregar estatísticas:', error)
    currentRule.value = null
    monthStats.value = {
      ...monthStats.value,
      total_operations: 0,
      total_volume: 0,
      is_required: false,
      status: 'Erro ao carregar',
    }
  }
}, { immediate: true })
</script>
