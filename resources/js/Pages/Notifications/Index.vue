<template>
  <AppLayout title="Notificações">
    <div class="py-12">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <!-- Header -->
        <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg mb-6">
          <div class="p-6 border-b border-gray-200">
            <div class="flex justify-between items-center">
              <div>
                <h2 class="text-2xl font-bold text-gray-900">Central de Notificações</h2>
                <p class="text-gray-600 mt-1">Gerencie suas notificações e alertas</p>
              </div>
              <div class="flex space-x-3">
                <button
                  @click="markAllAsRead"
                  :disabled="unreadCount === 0"
                  class="bg-blue-600 hover:bg-blue-700 disabled:bg-gray-400 text-white px-4 py-2 rounded-lg font-medium transition-colors"
                >
                  <svg class="w-5 h-5 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                  </svg>
                  Marcar Todas como Lidas
                </button>
                <Link :href="route('profile.edit')" 
                      class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg font-medium transition-colors">
                  <svg class="w-5 h-5 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                  </svg>
                  Configurações
                </Link>
              </div>
            </div>
          </div>
        </div>

        <!-- Cards de Estatísticas -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
          <StatCard
            title="Total de Notificações"
            :value="stats.total"
            icon="bell"
            color="blue"
          />
          <StatCard
            title="Não Lidas"
            :value="stats.unread"
            icon="bell-alert"
            color="red"
          />
          <StatCard
            title="Alertas de Preço"
            :value="stats.price_alerts"
            icon="trending-up"
            color="green"
          />
          <StatCard
            title="Trading Bot"
            :value="stats.trading_alerts"
            icon="robot"
            color="purple"
          />
        </div>

        <!-- Filtros -->
        <div class="bg-white rounded-lg shadow mb-6 p-6">
          <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Buscar</label>
              <input
                v-model="filters.search"
                type="text"
                placeholder="Buscar notificações..."
                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Tipo</label>
              <select
                v-model="filters.type"
                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
              >
                <option value="">Todos os tipos</option>
                <option value="price_alert">Alerta de Preço</option>
                <option value="trading_bot">Trading Bot</option>
                <option value="system">Sistema</option>
                <option value="report">Relatório</option>
                <option value="security">Segurança</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Status</label>
              <select
                v-model="filters.status"
                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
              >
                <option value="">Todos</option>
                <option value="unread">Não Lidas</option>
                <option value="read">Lidas</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Prioridade</label>
              <select
                v-model="filters.priority"
                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
              >
                <option value="">Todas</option>
                <option value="high">Alta</option>
                <option value="medium">Média</option>
                <option value="low">Baixa</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Período</label>
              <select
                v-model="filters.period"
                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
              >
                <option value="">Todos</option>
                <option value="today">Hoje</option>
                <option value="week">Esta Semana</option>
                <option value="month">Este Mês</option>
              </select>
            </div>
          </div>
        </div>

        <!-- Lista de Notificações -->
        <div class="bg-white rounded-lg shadow">
          <div class="p-6 border-b border-gray-200">
            <div class="flex justify-between items-center">
              <h3 class="text-lg font-medium text-gray-900">Notificações Recentes</h3>
              <div class="flex items-center space-x-2">
                <span class="text-sm text-gray-500">{{ notifications.total }} notificações</span>
                <button
                  @click="refreshNotifications"
                  class="text-gray-400 hover:text-gray-600 p-1 rounded"
                  title="Atualizar"
                >
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                  </svg>
                </button>
              </div>
            </div>
          </div>
          
          <div v-if="loading" class="p-8 text-center">
            <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600 mx-auto"></div>
            <p class="text-gray-500 mt-2">Carregando notificações...</p>
          </div>

          <div v-else-if="notifications.data.length === 0" class="p-8 text-center">
            <svg class="w-12 h-12 text-gray-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-5 5v-5zM9 7H4l5-5v5zm6 10V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2h6a2 2 0 002-2z"/>
            </svg>
            <h3 class="text-lg font-medium text-gray-900 mb-2">Nenhuma notificação encontrada</h3>
            <p class="text-gray-500 mb-4">Você está em dia com todas as suas notificações!</p>
          </div>

          <div v-else class="divide-y divide-gray-200">
            <div 
              v-for="notification in notifications.data" 
              :key="notification.id" 
              :class="[
                'p-6 hover:bg-gray-50 transition-colors cursor-pointer',
                !notification.read_at ? 'bg-blue-50 border-l-4 border-l-blue-500' : ''
              ]"
              @click="markAsRead(notification)"
            >
              <div class="flex items-start space-x-4">
                <!-- Ícone -->
                <div class="flex-shrink-0">
                  <div :class="getTypeIcon(notification.type).class" class="w-10 h-10 rounded-lg flex items-center justify-center">
                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="getTypeIcon(notification.type).path"/>
                    </svg>
                  </div>
                </div>

                <!-- Conteúdo -->
                <div class="flex-1 min-w-0">
                  <div class="flex items-center justify-between">
                    <h4 class="text-sm font-medium text-gray-900 truncate">{{ notification.title }}</h4>
                    <div class="flex items-center space-x-2">
                      <span :class="getPriorityClass(notification.priority)" class="px-2 py-1 text-xs font-medium rounded-full">
                        {{ getPriorityLabel(notification.priority) }}
                      </span>
                      <span class="text-xs text-gray-500">{{ formatDate(notification.created_at) }}</span>
                    </div>
                  </div>
                  <p class="text-sm text-gray-600 mt-1">{{ notification.message }}</p>
                  
                  <!-- Dados Adicionais -->
                  <div v-if="notification.data" class="mt-2">
                    <div v-if="notification.type === 'price_alert'" class="text-sm">
                      <span class="font-medium">{{ notification.data.symbol }}:</span>
                      <span :class="notification.data.direction === 'up' ? 'text-green-600' : 'text-red-600'" class="ml-1">
                        {{ formatCurrency(notification.data.price) }}
                        <svg class="w-3 h-3 inline ml-1" fill="currentColor" viewBox="0 0 20 20">
                          <path v-if="notification.data.direction === 'up'" fill-rule="evenodd" d="M5.293 7.707a1 1 0 010-1.414l4-4a1 1 0 011.414 0l4 4a1 1 0 01-1.414 1.414L10 4.414 6.707 7.707a1 1 0 01-1.414 0z" clip-rule="evenodd"/>
                          <path v-else fill-rule="evenodd" d="M14.707 12.293a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 111.414-1.414L10 15.586l3.293-3.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                        </svg>
                      </span>
                    </div>
                    
                    <div v-if="notification.type === 'trading_bot'" class="text-sm">
                      <span class="font-medium">Estratégia:</span> {{ notification.data.strategy_name }}
                      <span v-if="notification.data.profit" class="ml-2">
                        <span class="font-medium">Lucro:</span>
                        <span :class="notification.data.profit >= 0 ? 'text-green-600' : 'text-red-600'">
                          {{ formatCurrency(notification.data.profit) }}
                        </span>
                      </span>
                    </div>
                  </div>

                  <!-- Ações -->
                  <div v-if="notification.action_url" class="mt-3">
                    <Link 
                      :href="notification.action_url" 
                      class="text-blue-600 hover:text-blue-800 text-sm font-medium"
                      @click.stop
                    >
                      {{ notification.action_text || 'Ver Detalhes' }} →
                    </Link>
                  </div>
                </div>

                <!-- Status de Leitura -->
                <div class="flex-shrink-0">
                  <div v-if="!notification.read_at" class="w-2 h-2 bg-blue-500 rounded-full"></div>
                </div>
              </div>
            </div>
          </div>

          <!-- Paginação -->
          <div v-if="notifications.data.length > 0" class="px-6 py-4 border-t border-gray-200">
            <div class="flex items-center justify-between">
              <div class="text-sm text-gray-700">
                Mostrando {{ notifications.from }} a {{ notifications.to }} de {{ notifications.total }} resultados
              </div>
              <div class="flex space-x-2">
                <Link
                  v-if="notifications.prev_page_url"
                  :href="notifications.prev_page_url"
                  class="px-3 py-2 text-sm font-medium text-gray-500 bg-white border border-gray-300 rounded-md hover:bg-gray-50"
                >
                  Anterior
                </Link>
                <Link
                  v-if="notifications.next_page_url"
                  :href="notifications.next_page_url"
                  class="px-3 py-2 text-sm font-medium text-gray-500 bg-white border border-gray-300 rounded-md hover:bg-gray-50"
                >
                  Próximo
                </Link>
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
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import StatCard from '@/Components/StatCard.vue'

const props = defineProps({
  notifications: Object,
  stats: Object,
  filters: Object
})

const loading = ref(false)
const filters = ref({
  search: '',
  type: '',
  status: '',
  priority: '',
  period: ''
})

const unreadCount = computed(() => {
  return props.notifications.data.filter(n => !n.read_at).length
})

const formatCurrency = (value) => {
  return new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL'
  }).format(value)
}

const formatDate = (date) => {
  const now = new Date()
  const notificationDate = new Date(date)
  const diffInHours = (now - notificationDate) / (1000 * 60 * 60)
  
  if (diffInHours < 1) {
    const diffInMinutes = Math.floor((now - notificationDate) / (1000 * 60))
    return `${diffInMinutes}m atrás`
  } else if (diffInHours < 24) {
    return `${Math.floor(diffInHours)}h atrás`
  } else {
    return notificationDate.toLocaleDateString('pt-BR')
  }
}

const getTypeIcon = (type) => {
  const icons = {
    price_alert: {
      class: 'bg-green-500',
      path: 'M13 7h8m0 0v8m0-8l-8 8-4-4-6 6'
    },
    trading_bot: {
      class: 'bg-purple-500',
      path: 'M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z'
    },
    system: {
      class: 'bg-blue-500',
      path: 'M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z'
    },
    report: {
      class: 'bg-yellow-500',
      path: 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'
    },
    security: {
      class: 'bg-red-500',
      path: 'M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z'
    }
  }
  return icons[type] || icons.system
}

const getPriorityClass = (priority) => {
  const classes = {
    high: 'bg-red-100 text-red-800',
    medium: 'bg-yellow-100 text-yellow-800',
    low: 'bg-green-100 text-green-800'
  }
  return classes[priority] || 'bg-gray-100 text-gray-800'
}

const getPriorityLabel = (priority) => {
  const labels = {
    high: 'Alta',
    medium: 'Média',
    low: 'Baixa'
  }
  return labels[priority] || 'Normal'
}

const markAsRead = async (notification) => {
  if (!notification.read_at) {
    try {
      await router.post(route('notifications.mark-as-read', notification.id), {}, {
        preserveState: true,
        preserveScroll: true
      })
      notification.read_at = new Date().toISOString()
    } catch (error) {
      console.error('Erro ao marcar como lida:', error)
    }
  }
  
  // Navigate to action URL if exists
  if (notification.action_url) {
    router.visit(notification.action_url)
  }
}

const markAllAsRead = async () => {
  if (unreadCount.value === 0) return
  
  try {
    await router.post(route('notifications.mark-all-as-read'), {}, {
      preserveState: true,
      preserveScroll: true
    })
  } catch (error) {
    console.error('Erro ao marcar todas como lidas:', error)
  }
}

const refreshNotifications = () => {
  router.reload({ only: ['notifications', 'stats'] })
}
</script>

