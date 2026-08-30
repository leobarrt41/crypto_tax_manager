<template>
  <div class="app-layout min-h-screen bg-gray-50 flex">
  <!-- Sidebar -->
  <Sidebar ref="sidebarRef" />

  <!-- Main Content Area -->
  <div class="main-content flex-1 flex flex-col transition-all duration-300">
      
      <!-- Top Navigation Bar -->
     <header class="top-nav bg-white border-b px-6 py-1">

        <div class="flex items-center justify-between">
          
          <!-- Mobile Menu Button -->
          <button 
            @click="toggleMobileSidebar"
            class="md:hidden text-gray-500 hover:text-gray-700 focus:outline-none"
          >
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
            </svg>
          </button>

          <!-- Page Title -->
          <div class="flex-1 md:flex-none">
            <h1 class="text-xl font-semibold text-gray-800 md:text-2xl">
              {{ title || 'Crypto Tax Manager' }}
            </h1>
          </div>

          <!-- Top Right Actions -->
          <div class="flex items-center space-x-4">
            <button
              type="button"
              class="inline-flex h-10 w-10 items-center justify-center rounded-full text-gray-500 transition hover:bg-gray-100 hover:text-gray-800 focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white"
              :aria-label="isDark ? 'Ativar modo claro' : 'Ativar modo escuro'"
              :title="isDark ? 'Ativar modo claro' : 'Ativar modo escuro'"
              @click="toggleTheme"
            >
              <svg v-if="isDark" class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364-.707-.707M6.343 6.343l-.707-.707m12.728 0-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" />
              </svg>
              <svg v-else class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 1012 21a9.003 9.003 0 008.354-5.646z" />
              </svg>
            </button>
            
            <!-- Notifications -->
            <button class="relative text-gray-500 hover:text-gray-700">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-5 5v-5zM11 19H6a2 2 0 01-2-2V7a2 2 0 012-2h5m5 0v5"></path>
              </svg>
              <span v-if="notificationCount > 0" class="absolute -top-2 -right-2 bg-danger text-white text-xs rounded-full h-5 w-5 flex items-center justify-center">
                {{ notificationCount }}
              </span>
            </button>

            <!-- User Menu -->
            <div class="relative">
              <button 
                @click="showUserMenu = !showUserMenu"
                class="flex items-center space-x-2 text-gray-700 hover:text-gray-900 focus:outline-none"
              >
                <div class="w-8 h-8 bg-primary rounded-full flex items-center justify-center text-white text-sm font-bold">
                  {{ userInitials }}
                </div>
                <span class="hidden md:block text-sm font-medium">{{ userName }}</span>
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                </svg>
              </button>

              <!-- User Dropdown -->
              <div 
                v-if="showUserMenu"
                @click.away="showUserMenu = false"
                class="absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg py-1 z-50"
              >
                <Link :href="safeRoute('profile.edit')" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                  Perfil
                </Link>
                <Link :href="safeRoute('settings.index')" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                  Configurações
                </Link>
                <hr class="my-1">
                <button 
                  @click="logout"
                  class="block w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"
                >
                  Sair
                </button>
              </div>
            </div>

          </div>
        </div>
      </header>

      <!-- Page Content -->
      <main class="page-content p-6">
        
        <!-- Flash Messages -->
        <div v-if="flashMessage" class="mb-6">
          <div 
            class="alert p-4 rounded-lg mb-4"
            :class="flashMessageClass"
          >
            {{ flashMessage }}
          </div>
        </div>

        <!-- Page Content Slot -->
        <slot />
        
      </main>

      <!-- Footer -->
      <footer class="footer bg-white border-t border-gray-200 px-6 py-4 mt-8">
        <div class="flex items-center justify-between text-sm text-gray-600">
          <div>
            © 2024 Crypto Tax Manager. Todos os direitos reservados.
          </div>
          <div class="flex space-x-4">
            <a href="#" class="hover:text-gray-900">Suporte</a>
            <a href="#" class="hover:text-gray-900">Documentação</a>
            <a href="#" class="hover:text-gray-900">Privacidade</a>
          </div>
        </div>
      </footer>

    </div>

  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { Link, usePage, router } from '@inertiajs/vue3'
import Sidebar from '@/Components/Sidebar.vue'

// Props
const props = defineProps({
  title: {
    type: String,
    default: ''
  }
})

// Estado reativo
const sidebarRef = ref(null)
const showUserMenu = ref(false)
const notificationCount = ref(3) // Exemplo
const isDark = ref(false)
let colorSchemeQuery = null

// Acesso aos dados da página
const page = usePage()

// Computed properties
const user = computed(() => page.props?.auth?.user || null)
const userName = computed(() => user.value?.name || 'Usuário')
const userInitials = computed(() => {
  if (!user.value?.name) return 'U'
  return user.value.name
    .split(' ')
    .map(word => word.charAt(0))
    .join('')
    .substring(0, 2)
    .toUpperCase()
})

// Flash messages
const flashMessage = computed(() => {
  return page.props?.flash?.message || 
         page.props?.flash?.success || 
         page.props?.flash?.error || 
         page.props?.flash?.warning || 
         null
})

const flashMessageClass = computed(() => {
  if (page.props?.flash?.success) return 'alert-success'
  if (page.props?.flash?.error) return 'alert-danger'
  if (page.props?.flash?.warning) return 'alert-warning'
  return 'alert-info'
})

// ===== FUNÇÕES DE SEGURANÇA PARA ROTAS =====
// Função para verificar se uma rota existe de forma segura
const hasRoute = (routeName) => {
  try {
    if (typeof route !== 'function') {
      return false
    }
    
    if (typeof route().has === 'function') {
      return route().has(routeName)
    }
    
    const routes = route().routes || {}
    return routeName in routes
  } catch (error) {
    console.warn(`Erro ao verificar rota ${routeName}:`, error)
    return false
  }
}

// Função para obter uma rota de forma segura
const safeRoute = (routeName, params = {}) => {
  try {
    if (hasRoute(routeName)) {
      return route(routeName, params)
    }
    return '#'
  } catch (error) {
    console.warn(`Erro ao gerar rota ${routeName}:`, error)
    return '#'
  }
}

// Métodos
const toggleMobileSidebar = () => {
  if (sidebarRef.value) {
    sidebarRef.value.toggleSidebar()
  }
}

const logout = () => {
  // Usar safeRoute para logout também
  const logoutUrl = safeRoute('logout')
  if (logoutUrl !== '#') {
    router.post(logoutUrl)
  } else {
    // Fallback para URL direta
    router.post('/logout')
  }
}

const applyTheme = (theme, persist = true) => {
  isDark.value = theme === 'dark'
  document.documentElement.classList.toggle('dark', isDark.value)
  document.documentElement.style.colorScheme = theme
  if (persist) localStorage.setItem('crypto-tax-theme', theme)
}

const toggleTheme = () => applyTheme(isDark.value ? 'light' : 'dark')

const followSystemTheme = (event) => {
  if (localStorage.getItem('crypto-tax-theme')) return
  applyTheme(event.matches ? 'dark' : 'light', false)
}

// Fechar menus ao clicar fora
const handleClickOutside = (event) => {
  // Lógica para fechar menus
  showUserMenu.value = false
}

// Lifecycle
onMounted(() => {
  document.addEventListener('click', handleClickOutside)
  isDark.value = document.documentElement.classList.contains('dark')
  colorSchemeQuery = window.matchMedia('(prefers-color-scheme: dark)')
  colorSchemeQuery.addEventListener?.('change', followSystemTheme)
})

onUnmounted(() => {
  document.removeEventListener('click', handleClickOutside)
  colorSchemeQuery?.removeEventListener?.('change', followSystemTheme)
})

// Expor funções para o Sidebar usar
defineExpose({
  hasRoute,
  safeRoute
})
</script>

<style scoped>
/* Layout responsivo */
.app-layout {
  /* Estilos do layout principal */
}

.main-content {
  /* Estilos do conteúdo principal */
}

.top-nav {
  /* Estilos da barra superior */
}

.page-content {
  /* Estilos do conteúdo da página */
  min-height: calc(100vh - 200px);
}

/* Alerts */
.alert-success {
  background-color: #f0fdf4;
  border-left: 4px solid #10b981;
  color: #166534;
}

.alert-danger {
  background-color: #fef2f2;
  border-left: 4px solid #ef4444;
  color: #991b1b;
}

.alert-warning {
  background-color: #fffbeb;
  border-left: 4px solid #f59e0b;
  color: #92400e;
}

.alert-info {
  background-color: #eff6ff;
  border-left: 4px solid #3b82f6;
  color: #1e40af;
}

/* Cores personalizadas */
.bg-primary {
  background-color: var(--primary-color, #3b82f6);
}

.bg-danger {
  background-color: var(--danger-color, #ef4444);
}

/* Responsividade */
@media (max-width: 768px) {
  .main-content {
    margin-left: 0;
  }
}
</style>
