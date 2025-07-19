<template>
  <AppLayout title="Dashboard">
      <div class="pt-0 pb-6">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        
        <!-- Header com saudação -->
        <div class="mb-8">
          <h1 class="text-3xl font-bold text-gray-900">
            Bem-vindo ao Crypto Tax Manager
          </h1>
          <p class="mt-2 text-gray-600">
            Gerencie suas criptomoedas e mantenha-se em compliance com a Receita Federal
          </p>
        </div>

        <!-- Cards de Estatísticas -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
          <StatCard
            title="Portfolio Total"
            :value="stats.portfolio_total"
            format="currency"
            icon="currency"
            color="blue"
            :change="stats.portfolio_change"
            subtitle="Valor total em BRL"
          />
          
          <StatCard
            title="Ganho/Perda Mensal"
            :value="stats.monthly_pnl"
            format="currency"
            icon="chart"
            :color="stats.monthly_pnl >= 0 ? 'green' : 'red'"
            :change="stats.monthly_pnl_change"
            subtitle="Resultado do mês atual"
          />
          
          <StatCard
            title="Transações este Mês"
            :value="stats.monthly_transactions"
            icon="users"
            color="purple"
            subtitle="Operações realizadas"
          />
          
        <StatCard
            title="Status IN 1888"
            :value="stats.in1888_status?.message || 'Carregando...'"
            icon="document"
            :color="getComplianceColor(stats.in1888_status?.status)"
            :subtitle="stats.in1888_status?.description || ''"
          />


        </div>

        <!-- Seção de Gráficos -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
          
          <!-- Gráfico de Portfolio -->
          <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg p-6">
            <div class="flex items-center justify-between mb-4">
              <h3 class="text-lg font-medium text-gray-900">
                Evolução do Portfolio
              </h3>
              <select v-model="portfolioPeriod" class="text-sm border-gray-300 rounded-md">
                <option value="7d">7 dias</option>
                <option value="30d">30 dias</option>
                <option value="90d">90 dias</option>
                <option value="1y">1 ano</option>
              </select>
            </div>
            <div class="h-64 flex items-center justify-center bg-gray-50 rounded">
              <div class="text-center">
                <div class="text-4xl mb-2">📈</div>
                <p class="text-gray-500">Gráfico de evolução do portfolio</p>
                <p class="text-sm text-gray-400 mt-1">
                  Variação: {{ formatCurrency(stats.portfolio_variation) }}
                </p>
              </div>
            </div>
          </div>

          <!-- Top Assets -->
          <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg p-6">
            <h3 class="text-lg font-medium text-gray-900 mb-4">
              Principais Ativos
            </h3>
            <div class="space-y-4">
              <div 
                v-for="asset in topAssets" 
                :key="asset.symbol"
                class="flex items-center justify-between p-3 bg-gray-50 rounded-lg"
              >
                <div class="flex items-center">
                  <div class="w-8 h-8 bg-orange-500 rounded-full flex items-center justify-center text-white text-sm font-bold mr-3">
                    {{ asset.symbol.substring(0, 2) }}
                  </div>
                  <div>
                    <p class="font-medium text-gray-900">{{ asset.name }}</p>
                    <p class="text-sm text-gray-500">{{ asset.symbol }}</p>
                  </div>
                </div>
                <div class="text-right">
                  <p class="font-medium text-gray-900">
                    {{ formatCurrency(asset.value) }}
                  </p>
                  <p class="text-sm" :class="asset.change >= 0 ? 'text-green-600' : 'text-red-600'">
                    {{ asset.change >= 0 ? '+' : '' }}{{ asset.change.toFixed(2) }}%
                  </p>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Ações Rápidas -->
        <div class="mb-8">
          <h3 class="text-lg font-medium text-gray-900 mb-4">Ações Rápidas</h3>
          <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
            
            <QuickActionCard
              title="Importar Transações"
              description="Importe suas transações das exchanges automaticamente"
              icon="download"
              color="blue"
              href="/transactions/import"
            />
            
            <QuickActionCard
              title="Gerar IN 1888"
              description="Gere o arquivo para declaração à Receita Federal"
              icon="document"
              color="green"
              href="/tax-reports/in1888"
            />
            
            <QuickActionCard
              title="Trading Bot"
              description="Configure e monitore suas estratégias de trading"
              icon="play"
              color="purple"
              href="/trading-bot"
            />
            
            <QuickActionCard
              title="Relatórios Fiscais"
              description="Visualize relatórios detalhados para IR"
              icon="chart"
              color="yellow"
              href="/tax-reports"
            />
          </div>
        </div>

        <!-- Transações Recentes -->
        <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
          <div class="px-6 py-4 border-b border-gray-200">
            <div class="flex items-center justify-between">
              <h3 class="text-lg font-medium text-gray-900">
                Transações Recentes
              </h3>
              <Link 
                href="/transactions" 
                class="text-indigo-600 hover:text-indigo-900 text-sm font-medium"
              >
                Ver todas
              </Link>
            </div>
          </div>
          
          <div class="divide-y divide-gray-200">
            <div 
              v-for="transaction in recentTransactions" 
              :key="transaction.id"
              class="px-6 py-4 hover:bg-gray-50"
            >
              <div class="flex items-center justify-between">
                <div class="flex items-center">
                  <div class="flex-shrink-0">
                    <div 
                      class="w-10 h-10 rounded-full flex items-center justify-center text-white text-sm font-bold"
                      :class="getTransactionColor(transaction.type)"
                    >
                      {{ getTransactionIcon(transaction.type) }}
                    </div>
                  </div>
                  <div class="ml-4">
                    <p class="text-sm font-medium text-gray-900">
                      {{ transaction.type === 'buy' ? 'Compra' : 'Venda' }} de {{ transaction.asset }}
                    </p>
                    <p class="text-sm text-gray-500">
                      {{ formatDate(transaction.date) }} • {{ transaction.exchange }}
                    </p>
                  </div>
                </div>
                <div class="text-right">
                  <p class="text-sm font-medium text-gray-900">
                    {{ formatCurrency(transaction.amount) }}
                  </p>
                  <p class="text-sm text-gray-500">
                    {{ transaction.quantity }} {{ transaction.asset }}
                  </p>
                </div>
              </div>
            </div>
          </div>
        </div>

      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import StatCard from '@/Components/StatCard.vue'
import QuickActionCard from '@/Components/QuickActionCard.vue'

// Props recebidas do controller
const props = defineProps({
  stats: {
    type: Object,
    default: () => ({
      portfolio_total: 0,
      portfolio_change: 0,
      portfolio_variation: 0,
      monthly_pnl: 0,
      monthly_pnl_change: 0,
      monthly_transactions: 0,
      in1888_status: {
        status: 'pending',
        message: 'Pendente',
        description: 'Aguardando dados'
      }
    })
  },
  topAssets: {
    type: Array,
    default: () => []
  },
  recentTransactions: {
    type: Array,
    default: () => []
  }
})

// Estado reativo
const portfolioPeriod = ref('30d')

// Métodos auxiliares
const formatCurrency = (value) => {
  return new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL'
  }).format(value || 0)
}

const formatDate = (date) => {
  return new Date(date).toLocaleDateString('pt-BR')
}

const getComplianceColor = (status) => {
  const colors = {
    'compliant': 'green',
    'pending': 'yellow',
    'non_compliant': 'red'
  }
  return colors[status] || 'gray'
}

const getTransactionColor = (type) => {
  return type === 'buy' ? 'bg-green-500' : 'bg-red-500'
}

const getTransactionIcon = (type) => {
  return type === 'buy' ? '↗' : '↙'
}
</script>