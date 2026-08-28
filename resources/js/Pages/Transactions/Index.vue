<template>
  <AppLayout title="Transações">
    <div class="py-6">
      
      <!-- Header -->
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="md:flex md:items-center md:justify-between">
          <div class="flex-1 min-w-0">
            <h2 class="text-2xl font-bold leading-7 text-gray-900 sm:text-3xl sm:truncate">
              Transações
            </h2>
            <p class="mt-1 text-sm text-gray-500">
              Gerencie todas as suas transações de criptomoedas
            </p>
          </div>
          <div class="mt-4 flex md:mt-0 md:ml-4">
            <Link
              href="/transactions/import"
              class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary"
            >
              <svg class="-ml-1 mr-2 h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M9 19l3 3m0 0l3-3m-3 3V10"></path>
              </svg>
              Importar
            </Link>
            <Link
              href="/transactions/create"
              class="ml-3 inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary hover:bg-primary-dark focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary"
            >
              <svg class="-ml-1 mr-2 h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m4-6h6m-6 0H6"></path>
              </svg>
              Nova Transação
            </Link>

            <button
              type="button"
              @click="openPeriodDeletion"
              class="ml-3 inline-flex items-center px-4 py-2 border border-red-200 rounded-md shadow-sm text-sm font-medium text-red-700 bg-red-50 hover:bg-red-100 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500"
            >
              Excluir por período
            </button>

              <button
                    @click="deleteAllTransactions"
                    class="ml-3 inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500"
                  >
                    <svg class="-ml-1 mr-2 h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                    </svg>
                    Deletar Tudo
                  </button>
          </div>
        </div>
      </div>

      <!-- CORREÇÃO: Seletor de moeda -->
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mb-2 flex justify-end">
        <div class="flex items-center space-x-2">
          <label for="currency" class="text-sm text-gray-700">Exibir valores em:</label>
          <select 
            id="currency"
            v-model="displayCurrency" 
            class="border border-gray-300 rounded-md px-2 py-1 text-sm focus:outline-none focus:ring-primary focus:border-primary"
            @change="onCurrencyChange"
          >
            <option value="BRL">Real (BRL)</option>
            <option value="USDT">Dólar (USDT)</option>
          </select>
        </div>
      </div>

      <!-- CORREÇÃO: Stats Cards -->
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-6">
        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
          <StatCard
            title="Total de Transações"
            :value="stats.total_transactions"
            format="number"
            icon="chart-bar"
            color="blue"
          />
          <StatCard
            title="Volume Total"
            :value="totalVolume"
            :format="displayCurrency === 'BRL' ? 'currency-brl' : 'currency-usd'"
            icon="currency-dollar"
            color="green"
          />
          <StatCard
            title="Este Mês"
            :value="stats.this_month"
            format="number"
            icon="calendar"
            color="purple"
          />
          <StatCard
            title="Lucro/Prejuízo"
            :value="profitLoss"
            :format="displayCurrency === 'BRL' ? 'currency-brl' : 'currency-usd'"
            icon="trending-up"
            :color="profitLoss >= 0 ? 'green' : 'red'"
          />
        </div>
      </div>

      <!-- Filters -->
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-6">
        <div class="bg-white shadow rounded-lg p-6">
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            
            <!-- Search -->
            <div>
              <label for="search" class="block text-sm font-medium text-gray-700">Buscar</label>
              <input
                id="search"
                v-model="filters.search"
                type="text"
                placeholder="Buscar por ativo, hash..."
                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary focus:border-primary sm:text-sm"
                @input="debouncedSearch"
              />
            </div>

            <!-- Type Filter -->
            <div>
              <label for="type" class="block text-sm font-medium text-gray-700">Tipo</label>
              <select
                id="type"
                v-model="filters.type"
                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary focus:border-primary sm:text-sm"
                @change="applyFilters"
              >
                <option value="">Todos os tipos</option>
                <option value="trade">Spot</option>
                <option value="convert">Convert</option>
                <option value="deposit">Depósito</option>
                <option value="withdrawal">Saque</option>
                <option value="mining">Mineração</option>
                <option value="staking">Staking</option>
              </select>
            </div>

            <!-- Asset Filter -->
            <div>
              <label for="asset" class="block text-sm font-medium text-gray-700">Ativo</label>
              <select
                id="asset"
                v-model="filters.crypto_asset_id"
                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary focus:border-primary sm:text-sm"
                @change="applyFilters"
              >
                <option value="">Todos os ativos</option>
                <option v-for="asset in cryptoAssets" :key="asset.id" :value="asset.id">
                  {{ asset.symbol }} - {{ asset.name }}
                </option>
              </select>
            </div>

            <!-- Date Range -->
            <div>
              <label for="date_range" class="block text-sm font-medium text-gray-700">Período</label>
              <select
                id="date_range"
                v-model="filters.date_range"
                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary focus:border-primary sm:text-sm"
                @change="applyFilters"
              >
                <option value="">Todos os períodos</option>
                <option value="today">Hoje</option>
                <option value="week">Esta semana</option>
                <option value="month">Este mês</option>
                <option value="year">Este ano</option>
                <option value="custom">Personalizado</option>
              </select>
            </div>

          </div>

          <!-- Custom Date Range -->
          <div v-if="filters.date_range === 'custom'" class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <label for="start_date" class="block text-sm font-medium text-gray-700">Data inicial</label>
              <input
                id="start_date"
                v-model="filters.start_date"
                type="date"
                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary focus:border-primary sm:text-sm"
                @change="applyFilters"
              />
            </div>
            <div>
              <label for="end_date" class="block text-sm font-medium text-gray-700">Data final</label>
              <input
                id="end_date"
                v-model="filters.end_date"
                type="date"
                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary focus:border-primary sm:text-sm"
                @change="applyFilters"
              />
            </div>
          </div>

          <!-- Filter Actions -->
          <div class="mt-4 flex justify-between">
            <button
              @click="clearFilters"
              class="inline-flex items-center px-3 py-2 border border-gray-300 shadow-sm text-sm leading-4 font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary"
            >
              Limpar filtros
            </button>
            <button
              @click="exportTransactions"
              class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-white bg-primary hover:bg-primary-dark focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary"
            >
              <svg class="-ml-0.5 mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
              </svg>
              Exportar
            </button>
          </div>
        </div>
      </div>

      <!-- Transactions Table -->
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-6">
        <div class="bg-white shadow overflow-hidden sm:rounded-md">
          
          <!-- Loading State -->
          <div v-if="loading" class="p-6 text-center">
            <svg class="animate-spin h-8 w-8 text-primary mx-auto" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <p class="mt-2 text-sm text-gray-500">Carregando transações...</p>
          </div>

          <div v-else-if="transactions.data.length === 0" class="p-6 text-center">
            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
            </svg>
            <h3 class="mt-2 text-sm font-medium text-gray-900">Nenhuma transação encontrada</h3>
            <p class="mt-1 text-sm text-gray-500">
              {{ hasFilters ? 'Tente ajustar os filtros ou' : 'Comece' }} criando sua primeira transação.
            </p>
            <div class="mt-6">
              <Link
                href="/transactions/create"
                class="inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-primary hover:bg-primary-dark focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary"
              >
                <svg class="-ml-1 mr-2 h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                </svg>
                Nova Transação
              </Link>
            </div>
          </div>

          <!-- CORREÇÃO: Transactions List -->
          <ul v-else class="divide-y divide-gray-200">
            <li v-for="transaction in transactions?.data || []" :key="transaction?.id">
              <div class="px-4 py-4 flex items-center justify-between hover:bg-gray-50">

                <!-- Informações da transação -->
                <div class="flex items-center">
                  <!-- Tipo -->
                  <div class="flex-shrink-0">
                    <div :class="getTypeIconClass(transaction?.type)" class="h-10 w-10 rounded-full flex items-center justify-center">
                      <svg class="h-6 w-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path v-if="transaction?.type === 'trade'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path>
                        <path v-else-if="transaction?.type === 'convert'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path>
                        <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                      </svg>
                    </div>
                  </div>
                  
                  <!-- Detalhes -->
                  <div class="ml-4">
                    <div class="flex items-center">
                      <p class="text-sm font-medium text-gray-900">
                        {{ getTypeLabel(transaction?.type) }} {{ transaction?.from_asset }} → {{ transaction?.to_asset }}
                      </p>
                      <span :class="getTypeClass(transaction?.type)" class="ml-2 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium">
                        {{ getTypeLabel(transaction?.type) }}
                      </span>
                    </div>
                    <div class="mt-1 flex items-center text-sm text-gray-500">
                      <p>
                        {{ formatQuantity(transaction?.from_amount) }} {{ transaction?.from_asset }}
                        → {{ formatQuantity(transaction?.to_amount) }} {{ transaction?.to_asset }}
                      </p>
                      <span class="mx-2">&bull;</span>
                      <p>{{ formatCurrency(getUnitPrice(transaction), displayCurrency.value === 'BRL' ? 'BRL' : 'USDT') }} por unidade</p>
                      <template v-if="transaction?.fee_brl !== null">
                        <span class="mx-2">&bull;</span>
                        <p>Taxa: {{ formatCurrency(transaction?.fee_brl, 'BRL') }}</p>
                      </template>
                      <span class="mx-2">&bull;</span>
                      <p>{{ formatDate(transaction.date) }}</p>
                    </div>
                  </div>
                </div>

                <!-- CORREÇÃO: Ações -->
                <div class="flex items-center">
                  <div class="text-right mr-4">
                    <p class="text-sm font-medium text-gray-900">
                      {{ formatCurrency(getDisplayedTotal(transaction), displayCurrency) }}
                    </p>
                    <p class="text-xs text-gray-500">
                      Ref: {{ transaction?.reference || 'N/A' }}
                    </p>
                  </div>

                  <div class="flex items-center space-x-2">
                    <Link
                      v-if="transaction?.id"
                      :href="route('transactions.show', transaction.id)"
                      class="text-primary hover:text-primary-dark"
                    >
                      <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                      </svg>
                    </Link>
                    <Link
                      v-if="transaction?.id"
                      :href="`/transactions/${transaction.id}/edit`"
                      class="text-gray-400 hover:text-gray-600"
                    >
                      <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                      </svg>
                    </Link>
                    <button
                      v-if="transaction?.id"
                      @click="deleteTransaction(transaction.id)"
                      class="text-red-400 hover:text-red-600"
                    >
                      <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                      </svg>
                    </button>
                  </div>
                </div>

              </div>
            </li>
          </ul>

          <!-- Pagination -->
          <div v-if="transactions.data.length > 0" class="bg-white px-4 py-3 border-t border-gray-200 sm:px-6">
            <div class="flex items-center justify-between">
              <!-- Mobile Pagination -->
              <div class="flex-1 flex justify-between sm:hidden">
                <button
                  v-if="transactions.prev_page_url"
                  @click="goToPage(transactions.current_page - 1)"
                  class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50"
                >
                  Anterior
                </button>
                <button
                  v-if="transactions.next_page_url"
                  @click="goToPage(transactions.current_page + 1)"
                  class="ml-3 relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50"
                >
                  Próximo
                </button>
              </div>
              
              <!-- Desktop Pagination -->
              <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
                <div>
                  <p class="text-sm text-gray-700">
                    Mostrando
                    <span class="font-medium">{{ transactions.from }}</span>
                    a
                    <span class="font-medium">{{ transactions.to }}</span>
                    de
                    <span class="font-medium">{{ transactions.total }}</span>
                    resultados
                  </p>
                </div>
                <div>
                  <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                    <!-- Previous Button -->
                    <button
                      @click="goToPage(transactions.current_page - 1)"
                      :disabled="!transactions.prev_page_url"
                      :class="[
                        'relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium',
                        transactions.prev_page_url 
                          ? 'text-gray-500 hover:bg-gray-50 cursor-pointer' 
                          : 'text-gray-300 cursor-not-allowed'
                      ]"
                    >
                      <span class="sr-only">Anterior</span>
                      <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" />
                      </svg>
                    </button>
                    
                    <!-- Page Numbers -->
                    <template v-for="page in paginationPages" :key="page">
                      <button
                        v-if="page !== '...'"
                        @click="goToPage(page)"
                        :class="[
                          'relative inline-flex items-center px-4 py-2 border text-sm font-medium',
                          page === transactions.current_page
                            ? 'z-10 bg-primary border-primary text-white'
                            : 'bg-white border-gray-300 text-gray-700 hover:bg-gray-50'
                        ]"
                      >
                        {{ page }}
                      </button>
                      <span
                        v-else
                        class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700"
                      >
                        ...
                      </span>
                    </template>
                    
                    <!-- Next Button -->
                    <button
                      @click="goToPage(transactions.current_page + 1)"
                      :disabled="!transactions.next_page_url"
                      :class="[
                        'relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium',
                        transactions.next_page_url 
                          ? 'text-gray-500 hover:bg-gray-50 cursor-pointer' 
                          : 'text-gray-300 cursor-not-allowed'
                      ]"
                    >
                      <span class="sr-only">Próximo</span>
                      <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" />
                      </svg>
                    </button>
                  </nav>
                </div>
              </div>
            </div>
          </div>

        </div>
      </div>

      <div v-if="periodDeletion.open" class="fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true" aria-labelledby="delete-period-title">
        <div class="fixed inset-0 bg-gray-900/50" @click="closePeriodDeletion"></div>
        <div class="flex min-h-full items-center justify-center p-4">
          <div class="relative w-full max-w-lg rounded-lg bg-white shadow-xl">
            <div class="px-6 py-4 border-b border-gray-200 flex items-start justify-between gap-4">
              <div>
                <h3 id="delete-period-title" class="text-lg font-semibold text-gray-900">Excluir transações por período</h3>
                <p class="mt-1 text-sm text-gray-500">A operação remove apenas suas transações do período escolhido.</p>
              </div>
              <button type="button" @click="closePeriodDeletion" class="text-gray-400 hover:text-gray-600" aria-label="Fechar">×</button>
            </div>

            <div class="px-6 py-5 space-y-4">
              <div v-if="periodDeletion.error" class="rounded-md bg-red-50 p-3 text-sm text-red-700">{{ periodDeletion.error }}</div>

              <template v-if="!periodDeletion.preview">
                <div>
                  <label for="delete-period-year" class="block text-sm font-medium text-gray-700">Ano</label>
                  <select id="delete-period-year" v-model="periodDeletion.year" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-red-500 focus:border-red-500">
                    <option v-for="year in deletionYears" :key="year" :value="year">{{ year }}</option>
                  </select>
                </div>
                <div>
                  <label for="delete-period-month" class="block text-sm font-medium text-gray-700">Mês <span class="font-normal text-gray-500">(opcional)</span></label>
                  <select id="delete-period-month" v-model="periodDeletion.month" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-red-500 focus:border-red-500">
                    <option value="">Ano inteiro</option>
                    <option v-for="month in months" :key="month.value" :value="month.value">{{ month.label }}</option>
                  </select>
                </div>
                <p class="rounded-md bg-amber-50 p-3 text-sm text-amber-800">Depois da exclusão, o sistema recalculará o FIFO a partir do ano selecionado. Confirme a prévia antes de continuar.</p>
              </template>

              <template v-else>
                <div class="rounded-md bg-red-50 border border-red-100 p-4">
                  <p class="font-medium text-red-900">Período: {{ periodDeletion.preview.period_label }}</p>
                  <p class="mt-1 text-sm text-red-800"><strong>{{ periodDeletion.preview.transactions_count }}</strong> transação(ões) serão excluídas.</p>
                  <p class="mt-1 text-sm text-red-800">Volume informado: <strong>{{ formatCurrency(periodDeletion.preview.total_brl, 'BRL') }}</strong>.</p>
                  <p v-if="periodDeletion.preview.date_from" class="mt-1 text-xs text-red-700">Movimentações de {{ formatDate(periodDeletion.preview.date_from) }} até {{ formatDate(periodDeletion.preview.date_to) }}.</p>
                </div>

                <template v-if="periodDeletion.preview.transactions_count > 0">
                  <label for="delete-period-confirmation" class="block text-sm font-medium text-gray-700">
                    Para confirmar, digite <strong>{{ periodDeletion.preview.confirmation_phrase }}</strong>
                  </label>
                  <input id="delete-period-confirmation" v-model="periodDeletion.confirmation" type="text" autocomplete="off" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-red-500 focus:border-red-500" />
                </template>
                <p v-else class="text-sm text-gray-600">Nenhuma transação foi encontrada. Nada será excluído.</p>
              </template>
            </div>

            <div class="px-6 py-4 bg-gray-50 border-t border-gray-200 flex justify-end gap-3">
              <button type="button" @click="periodDeletion.preview ? resetPeriodPreview() : closePeriodDeletion()" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                {{ periodDeletion.preview ? 'Voltar' : 'Cancelar' }}
              </button>
              <button
                v-if="!periodDeletion.preview"
                type="button"
                @click="previewPeriodDeletion"
                :disabled="periodDeletion.loading"
                class="px-4 py-2 border border-transparent rounded-md text-sm font-medium text-white bg-red-600 hover:bg-red-700 disabled:opacity-50"
              >
                {{ periodDeletion.loading ? 'Consultando...' : 'Ver prévia' }}
              </button>
              <button
                v-else-if="periodDeletion.preview.transactions_count > 0"
                type="button"
                @click="destroyPeriod"
                :disabled="periodDeletion.loading || periodDeletion.confirmation !== periodDeletion.preview.confirmation_phrase"
                class="px-4 py-2 border border-transparent rounded-md text-sm font-medium text-white bg-red-600 hover:bg-red-700 disabled:opacity-50"
              >
                {{ periodDeletion.loading ? 'Excluindo...' : 'Excluir período' }}
              </button>
            </div>
          </div>
        </div>
      </div>

    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import StatCard from '@/Components/StatCard.vue'
import { Link } from '@inertiajs/vue3'
import { route } from 'ziggy-js'

// Variáveis de estado
const loading = ref(false)
const displayCurrency = ref('BRL')
const currentYear = new Date().getFullYear()
const deletionYears = Array.from({ length: currentYear - 2009 + 1 }, (_, index) => currentYear - index)
const months = [
  { value: 1, label: 'Janeiro' }, { value: 2, label: 'Fevereiro' }, { value: 3, label: 'Março' },
  { value: 4, label: 'Abril' }, { value: 5, label: 'Maio' }, { value: 6, label: 'Junho' },
  { value: 7, label: 'Julho' }, { value: 8, label: 'Agosto' }, { value: 9, label: 'Setembro' },
  { value: 10, label: 'Outubro' }, { value: 11, label: 'Novembro' }, { value: 12, label: 'Dezembro' },
]
const periodDeletion = ref({
  open: false,
  year: currentYear,
  month: '',
  preview: null,
  confirmation: '',
  loading: false,
  error: '',
})

// Props
const props = defineProps({
  transactions: Object,
  stats: Object,
  cryptoAssets: Array,
  filters: Object,
})

// CORREÇÃO: Computeds para volume e lucro/prejuízo
const totalVolume = computed(() => {
  if (!props.transactions?.data) return 0
  
  return props.transactions.data.reduce((sum, tx) => {
    const value = displayCurrency.value === 'BRL' 
      ? (tx.total_brl || 0) 
      : (tx.total_usdt || 0)
    return sum + Number(value)
  }, 0)
})

const profitLoss = computed(() => {
  // Por enquanto retorna 0, será implementado com FIFO
  return 0
})

// CORREÇÃO: Formatação de totais
const getDisplayedTotal = (transaction) => {
  if (!transaction) return 0
  
  const total = displayCurrency.value === 'BRL'
    ? (transaction.total_brl || 0)
    : (transaction.total_usdt || 0)
  
  return Number(total)
}

const getUnitPrice = (transaction) => {
  if (!transaction) return 0

  const price = Number(transaction.price) || 0

  if (transaction.type !== 'convert') {
    return price
  }

  const toAmount = Number(transaction.to_amount) || 0
  if (toAmount === 0) {
    return 0
  }

  if (displayCurrency.value === 'BRL') {
    const totalBrl = Number(transaction.total_brl) || 0
    if (totalBrl > 0) {
      return totalBrl / toAmount
    }
  }

  const totalUsdt = Number(transaction.total_usdt) || 0
  if (totalUsdt > 0) {
    return totalUsdt / toAmount
  }

  return price
}

// Computed para gerar os números de página com elipses
const paginationPages = computed(() => {
  if (!props.transactions?.last_page) return []
  
  const currentPage = props.transactions.current_page
  const lastPage = props.transactions.last_page
  const delta = 2 // Número de páginas a mostrar antes e depois da página atual
  const pages = []
  
  // Se houver 7 ou menos páginas, mostrar todas
  if (lastPage <= 7) {
    for (let i = 1; i <= lastPage; i++) {
      pages.push(i)
    }
    return pages
  }
  
  // Sempre mostrar primeira página
  pages.push(1)
  
  // Calcular o intervalo de páginas a mostrar
  let start = Math.max(2, currentPage - delta)
  let end = Math.min(lastPage - 1, currentPage + delta)
  
  // Ajustar o intervalo se estiver muito próximo do início ou fim
  if (currentPage - delta <= 2) {
    end = Math.min(lastPage - 1, 5)
  }
  if (currentPage + delta >= lastPage - 1) {
    start = Math.max(2, lastPage - 4)
  }
  
  // Adicionar elipse após a primeira página se necessário
  if (start > 2) {
    pages.push('...')
  }
  
  // Adicionar páginas do intervalo
  for (let i = start; i <= end; i++) {
    pages.push(i)
  }
  
  // Adicionar elipse antes da última página se necessário
  if (end < lastPage - 1) {
    pages.push('...')
  }
  
  // Sempre mostrar última página
  if (lastPage > 1) {
    pages.push(lastPage)
  }
  
  return pages
})

// Filtros
const filters = ref({
  search: props.filters?.search || '',
  type: props.filters?.type || '',
  crypto_asset_id: props.filters?.crypto_asset_id || '',
  date_range: props.filters?.date_range || '',
  start_date: props.filters?.start_date || '',
  end_date: props.filters?.end_date || '',
})

const hasFilters = computed(() => Object.values(filters.value).some(v => v !== ''))

// CORREÇÃO: Função para mudança de moeda
const onCurrencyChange = () => {
  console.log('💱 Moeda alterada para:', displayCurrency.value)
  // Força reatividade dos computeds
}




// Ações
const applyFilters = () => {
  loading.value = true
  router.get('/transactions', filters.value, {
    preserveState: true,
    onFinish: () => loading.value = false,
  })
}

const clearFilters = () => {
  filters.value = { search: '', type: '', crypto_asset_id: '', date_range: '', start_date: '', end_date: '' }
  applyFilters()
}

const debouncedSearch = debounce(applyFilters, 500)

const goToPage = (page) => {
  loading.value = true
  router.get('/transactions', { ...filters.value, page }, {
    preserveState: true,
    onFinish: () => loading.value = false,
  })
}

const deleteTransaction = (id) => {
  if (confirm('Tem certeza que deseja excluir esta transação?')) {
    router.delete(`/transactions/${id}`)
  }
}

const openPeriodDeletion = () => {
  periodDeletion.value = {
    open: true,
    year: currentYear,
    month: '',
    preview: null,
    confirmation: '',
    loading: false,
    error: '',
  }
}

const closePeriodDeletion = () => {
  if (!periodDeletion.value.loading) {
    periodDeletion.value.open = false
  }
}

const resetPeriodPreview = () => {
  periodDeletion.value.preview = null
  periodDeletion.value.confirmation = ''
  periodDeletion.value.error = ''
}

const deletionPayload = () => ({
  year: Number(periodDeletion.value.year),
  ...(periodDeletion.value.month !== '' ? { month: Number(periodDeletion.value.month) } : {}),
})

const previewPeriodDeletion = async () => {
  periodDeletion.value.loading = true
  periodDeletion.value.error = ''

  try {
    const query = new URLSearchParams(deletionPayload()).toString()
    const response = await fetch(`/transactions/delete-period/preview?${query}`, {
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    })
    const payload = await response.json()

    if (!response.ok) {
      throw new Error(Object.values(payload.errors || {}).flat().join(' ') || 'Não foi possível consultar o período.')
    }

    periodDeletion.value.preview = payload
  } catch (error) {
    periodDeletion.value.error = error.message || 'Não foi possível consultar o período.'
  } finally {
    periodDeletion.value.loading = false
  }
}

const destroyPeriod = () => {
  periodDeletion.value.loading = true
  periodDeletion.value.error = ''

  router.delete('/transactions/delete-period', {
    data: { ...deletionPayload(), confirmation: periodDeletion.value.confirmation },
    preserveScroll: true,
    onSuccess: () => {
      periodDeletion.value.open = false
      alert('As transações do período foram excluídas e o FIFO foi recalculado.')
    },
    onError: (errors) => {
      periodDeletion.value.error = Object.values(errors || {}).flat().join(' ') || 'Não foi possível excluir o período.'
    },
    onFinish: () => {
      periodDeletion.value.loading = false
    },
  })
}

const deleteAllTransactions = () => {
  // Primeira confirmação, mais simples.
  if (confirm('ATENÇÃO: Esta ação é irreversível e irá apagar TODAS as suas transações.\n\nTem certeza que deseja continuar?')) {
    
    // Segunda confirmação, pedindo para digitar "DELETAR TUDO"
    const confirmText = prompt('Para confirmar, digite "DELETAR TUDO" (sem aspas):')
    
    if (confirmText === 'DELETAR TUDO') {
      router.delete('/transactions/delete-all', {
        onSuccess: () => {
          alert('Todas as transações foram excluídas com sucesso.')
        },
        onError: () => {
          alert('Ocorreu um erro ao tentar excluir as transações.')
        }
      })
    } else {
      alert('Confirmação cancelada. As transações não foram excluídas.')
    }
  }
}

const exportTransactions = () => {
  window.location.href = '/transactions/export?' + new URLSearchParams(filters.value)
}

// Helpers
const getTypeLabel = (type) => {
  const labels = {
    trade: 'Spot',
    convert: 'Convert',
    deposit: 'Depósito',
    withdrawal: 'Saque',
    mining: 'Mineração',
    staking: 'Staking',
  }
  return labels[type] || type
}

const getTypeClass = (type) => {
  const classes = {
    trade: 'bg-blue-100 text-blue-800',
    convert: 'bg-purple-100 text-purple-800',
    deposit: 'bg-green-100 text-green-800',
    withdrawal: 'bg-red-100 text-red-800',
    mining: 'bg-yellow-100 text-yellow-800',
    staking: 'bg-indigo-100 text-indigo-800',
  }
  return classes[type] || 'bg-gray-100 text-gray-800'
}

const getTypeIconClass = (type) => {
  const classes = {
    trade: 'bg-blue-500',
    convert: 'bg-purple-500',
    deposit: 'bg-green-500',
    withdrawal: 'bg-red-500',
    mining: 'bg-yellow-500',
    staking: 'bg-indigo-500',
  }
  return classes[type] || 'bg-gray-500'
}

const formatDate = (date) => {
  if (!date) return 'Data não informada'

  // Datas vindas da prévia fiscal são YYYY-MM-DD e não devem sofrer conversão UTC.
  if (/^\d{4}-\d{2}-\d{2}$/.test(date)) {
    const [year, month, day] = date.split('-')
    return `${day}/${month}/${year}`
  }

  const parsedDate = new Date(date)
  if (Number.isNaN(parsedDate.getTime())) return 'Data não informada'

  return parsedDate.toLocaleDateString('pt-BR', {
    timeZone: 'America/Sao_Paulo',
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

const formatCurrency = (value, currency = 'BRL') => {
  const numValue = Number(value) || 0
  
  if (currency === 'BRL') {
    return new Intl.NumberFormat('pt-BR', {
      style: 'currency',
      currency: 'BRL',
    }).format(numValue)
  }
  
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
  }).format(numValue)
}

const formatQuantity = (value) => {
  const numValue = Number(value) || 0
  return new Intl.NumberFormat('pt-BR', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 8,
  }).format(numValue)
}

// Debounce helper
function debounce(func, wait) {
  let timeout
  return function executedFunction(...args) {
    const later = () => {
      clearTimeout(timeout)
      func(...args)
    }
    clearTimeout(timeout)
    timeout = setTimeout(later, wait)
  }
}
</script>
