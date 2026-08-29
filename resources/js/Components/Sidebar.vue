<template>
  <aside class="sidebar bg-white shadow-lg w-64 fixed left-0 top-0 bottom-0 z-50 flex flex-col transition-transform duration-300">

    
    <!-- Header do Sidebar -->
    <div class="sidebar-header p-6 border-b border-gray-200">
      <div class="flex items-center justify-between">
        <h2 class="text-xl font-bold text-gray-800">
          Crypto Tax Manager
        </h2>
        <button @click="toggleSidebar" class="md:hidden text-gray-500 hover:text-gray-700">
          <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
          </svg>
        </button>
      </div>
    </div>

    <!-- Navigation Menu -->
    <nav class="sidebar-nav p-4 overflow-y-auto flex-1">
      <ul class="space-y-2">
        
        <!-- Dashboard -->
        <li>
          <Link 
            href="/dashboard" 
            class="nav-item flex items-center p-3 rounded-lg transition-colors"
            :class="isActive('/dashboard') ? 'bg-primary text-white' : 'text-gray-700 hover:bg-gray-100'"
          >
            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2H5a2 2 0 00-2-2z"></path>
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5a2 2 0 012-2h4a2 2 0 012 2v6H8V5z"></path>
            </svg>
            Dashboard
          </Link>
        </li>

        <!-- Transações -->
        <li>
          <Link 
            href="/transactions" 
            class="nav-item flex items-center p-3 rounded-lg transition-colors"
            :class="isActive('/transactions') ? 'bg-primary text-white' : 'text-gray-700 hover:bg-gray-100'"
          >
            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
            </svg>
            Transações
          </Link>
        </li>

        <!-- Portfolio -->
        <li>
          <Link 
            href="/portfolio" 
            class="nav-item flex items-center p-3 rounded-lg transition-colors"
            :class="isActive('/portfolio') ? 'bg-primary text-white' : 'text-gray-700 hover:bg-gray-100'"
          >
            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 8v8m-4-5v5m-4-2v2m-2 4h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
            </svg>
            Portfolio
          </Link>
        </li>

        <!-- Separador -->
        <li class="border-t border-gray-200 my-4"></li>

        <!-- Trading Bot -->
        <li>
          <div class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2 px-3">
            Trading Bot
          </div>
        </li>

        <li>
          <Link
            href="/trading-bot"
            class="nav-item flex items-center p-3 rounded-lg transition-colors"
            :class="isActive('/trading-bot') ? 'bg-primary text-white' : 'text-gray-700 hover:bg-gray-100'"
          >
            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path>
            </svg>
            Visão Geral
          </Link>
        </li>

        <li>
          <Link
            href="/trading-bot/strategies"
            class="nav-item flex items-center p-3 rounded-lg transition-colors"
            :class="isActive('/trading-bot') ? 'bg-primary text-white' : 'text-gray-700 hover:bg-gray-100'"
          >
            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4v2m0-6V4m12 14a2 2 0 100-4m0 4v2m0-6V4"></path>
            </svg>
            Estratégias para Backtesting
          </Link>
        </li>

        <li class="nav-item flex items-center p-3 rounded-lg text-gray-400 cursor-not-allowed" title="Página ainda não disponível">
          <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
          </svg>
          <span>Backtesting</span>
          <span class="ml-auto text-[10px] uppercase">Em breve</span>
        </li>

        <li class="px-3 pt-2 text-xs font-semibold text-gray-500 uppercase tracking-wider">Operações</li>

        <li class="nav-item flex items-center py-2 pl-11 pr-3 rounded-lg text-sm text-gray-400 cursor-not-allowed" title="Página ainda não disponível">
          <span>Criar Operação</span>
          <span class="ml-auto text-[10px] uppercase">Em breve</span>
        </li>

        <li>
          <Link
            href="/bot-orders"
            class="nav-item flex items-center py-2 pl-11 pr-3 rounded-lg text-sm transition-colors"
            :class="isActive('/bot-orders') ? 'bg-primary text-white' : 'text-gray-700 hover:bg-gray-100'"
          >
            Operações Ativas
          </Link>
        </li>

        <li>
          <Link
            href="/trading-logs"
            class="nav-item flex items-center py-2 pl-11 pr-3 rounded-lg text-sm transition-colors"
            :class="isActive('/trading-logs') ? 'bg-primary text-white' : 'text-gray-700 hover:bg-gray-100'"
          >
            Histórico
          </Link>
        </li>

        <!-- Separador -->
        <li class="border-t border-gray-200 my-4"></li>

        <!-- Relatórios Fiscais -->
        <li>
          <div class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2 px-3">
            Relatórios Fiscais
          </div>
        </li>

        <!-- Declarações de criptoativos -->
        <li>
          <Link 
            href="/tax-reports/in1888" 
            class="nav-item flex items-center p-3 rounded-lg transition-colors"
            :class="isActive('/tax-reports/in1888') ? 'bg-success text-white' : 'text-gray-700 hover:bg-gray-100'"
          >
            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
            </svg>
            Declarações de Criptoativos
          </Link>
        </li>

        <!-- Relatórios IR -->
        <li>
          <Link 
            href="/reports/relatorio-ir" 
            class="nav-item flex items-center p-3 rounded-lg transition-colors"
            :class="isActive('/reports/relatorio-ir') ? 'bg-success text-white' : 'text-gray-700 hover:bg-gray-100'"
          >
            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z"></path>
            </svg>
            Relatórios IR
          </Link>
        </li>

        <!-- Separador -->
        <li class="border-t border-gray-200 my-4"></li>

        <!-- Configurações -->
        <li>
          <div class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2 px-3">
            Configurações
          </div>
        </li>

        <!-- Chaves de API -->
        <li class="mb-4">
          <Link 
            href="/exchanges/keys" 
            class="nav-item flex items-center p-3 rounded-lg transition-colors"
            :class="isActive('/exchanges/keys') ? 'bg-warning text-white' : 'text-gray-700 hover:bg-gray-100'"
          >
            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"></path>
            </svg>
            Chaves de API
          </Link>
        </li>

        <!-- SEÇÃO DE ADMINISTRAÇÃO (apenas para admins) -->
        <template v-if="isAdmin">
          <!-- Separador -->
          <li class="border-t border-gray-200 my-4"></li>

          <!-- Título da seção Admin -->
          <li>
            <div class="text-xs font-semibold text-red-600 uppercase tracking-wider mb-2 px-3 flex items-center">
              <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.031 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
              </svg>
              Administração
            </div>
          </li>

          <!-- Crypto Assets -->
          <li>
            <Link 
              href="/crypto-assets" 
              class="nav-item flex items-center p-3 rounded-lg transition-colors"
              :class="isActive('/crypto-assets') ? 'bg-red-600 text-white' : 'text-gray-700 hover:bg-red-50 hover:text-red-700'"
            >
              <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
              </svg>
              Crypto Assets
              <span class="ml-auto bg-red-100 text-red-800 text-xs px-2 py-1 rounded-full">Admin</span>
            </Link>
          </li>

          <!-- Gerenciar Usuários -->
          <li>
            <Link 
              href="/admin/users" 
              class="nav-item flex items-center p-3 rounded-lg transition-colors"
              :class="isActive('/admin/users') ? 'bg-red-600 text-white' : 'text-gray-700 hover:bg-red-50 hover:text-red-700'"
            >
              <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z"></path>
              </svg>
              Usuários
              <span class="ml-auto bg-red-100 text-red-800 text-xs px-2 py-1 rounded-full">Admin</span>
            </Link>
          </li>

          <!-- Configurações do Sistema -->
          <li>
            <Link 
              href="/admin/settings" 
              class="nav-item flex items-center p-3 rounded-lg transition-colors"
              :class="isActive('/admin/settings') ? 'bg-red-600 text-white' : 'text-gray-700 hover:bg-red-50 hover:text-red-700'"
            >
              <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
              </svg>
              Sistema
              <span class="ml-auto bg-red-100 text-red-800 text-xs px-2 py-1 rounded-full">Admin</span>
            </Link>
          </li>
        </template>

      </ul>
    </nav>

    <!-- Footer do Sidebar -->
    <div class="sidebar-footer p-4 border-t border-gray-200 bg-gray-50">
      <div class="flex items-center justify-between">
        <div class="flex items-center">
          <div class="w-8 h-8 rounded-full flex items-center justify-center text-white text-sm font-bold"
               :class="isAdmin ? 'bg-red-600' : 'bg-primary'">
            {{ userInitials }}
          </div>
          <div class="ml-3">
            <p class="text-sm font-medium text-gray-700 flex items-center">
              {{ userName }}
              <span v-if="isAdmin" class="ml-2 bg-red-100 text-red-800 text-xs px-2 py-1 rounded-full">Admin</span>
            </p>
            <p class="text-xs text-gray-500">{{ userEmail }}</p>
          </div>
        </div>
        <button @click="logout" class="text-gray-400 hover:text-gray-600">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
          </svg>
        </button>
      </div>
    </div>

  </aside>

  <!-- Overlay para mobile -->
  <div 
    v-if="isOpen" 
    @click="closeSidebar"
    class="fixed inset-0 bg-black bg-opacity-50 z-40 md:hidden"
  ></div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { Link, usePage, router } from '@inertiajs/vue3'

// Props
defineProps({
  // Adicione props se necessário
})

// Estado reativo
const isOpen = ref(false)

// Acesso aos dados da página
const page = usePage()

// Computed properties
const user = computed(() => page.props?.auth?.user || null)
const userName = computed(() => user.value?.name || 'Usuário')
const userEmail = computed(() => user.value?.email || '')
const isAdmin = computed(() => user.value?.is_admin || false)
const userInitials = computed(() => {
  if (!user.value?.name) return 'U'
  return user.value.name
    .split(' ')
    .map(word => word.charAt(0))
    .join('')
    .substring(0, 2)
    .toUpperCase()
})

// Métodos
const toggleSidebar = () => {
  isOpen.value = !isOpen.value
}

const closeSidebar = () => {
  isOpen.value = false
}

const isActive = (path) => {
  try {
    return window.location.pathname.startsWith(path)
  } catch (error) {
    return false
  }
}

const logout = () => {
  router.post('/logout')
}

// Expor métodos para componente pai
defineExpose({
  toggleSidebar,
  closeSidebar
})
</script>

<style scoped>
.sidebar {
  /* Estilos específicos do sidebar */
}

.nav-item {
  /* Estilos dos itens de navegação */
}

/* Responsividade */
@media (min-width: 768px) {
  .sidebar {
    position: relative;
    transform: translateX(0) !important;
  }
}

/* Cores personalizadas */
.bg-primary {
  background-color: var(--primary-color, #3b82f6);
}

.bg-success {
  background-color: var(--success-color, #10b981);
}

.bg-warning {
  background-color: var(--warning-color, #f59e0b);
}

.bg-secondary {
  background-color: var(--secondary-color, #6b7280);
}

/* Estilos específicos para seção admin */
.hover\:bg-red-50:hover {
  background-color: #fef2f2;
}

.hover\:text-red-700:hover {
  color: #b91c1c;
}
</style>
