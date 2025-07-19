<template>
  <AppLayout title="Editar Perfil">
    <div class="py-12">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <!-- Header -->
        <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
          <div class="p-6 border-b border-gray-200">
            <div class="flex justify-between items-center">
              <div>
                <h2 class="text-2xl font-bold text-gray-900">Editar Perfil</h2>
                <p class="text-gray-600 mt-1">Gerencie suas informações pessoais e configurações da conta</p>
              </div>
              <div class="flex space-x-3">
                <Link :href="route('dashboard')" 
                      class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg font-medium transition-colors">
                  <svg class="w-5 h-5 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                  </svg>
                  Voltar ao Dashboard
                </Link>
              </div>
            </div>
          </div>
        </div>

        <!-- Informações do Perfil -->
        <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
          <div class="p-6 border-b border-gray-200">
            <h3 class="text-lg font-medium text-gray-900">Informações Pessoais</h3>
            <p class="text-gray-600 text-sm mt-1">Atualize suas informações pessoais e endereço de email.</p>
          </div>
          
          <form @submit.prevent="updateProfile" class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
              <!-- Nome -->
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Nome Completo *</label>
                <input
                  v-model="profileForm.name"
                  type="text"
                  required
                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                  :class="{ 'border-red-500': profileForm.errors.name }"
                />
                <p v-if="profileForm.errors.name" class="text-red-500 text-sm mt-1">{{ profileForm.errors.name }}</p>
              </div>

              <!-- Email -->
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Email *</label>
                <input
                  v-model="profileForm.email"
                  type="email"
                  required
                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                  :class="{ 'border-red-500': profileForm.errors.email }"
                />
                <p v-if="profileForm.errors.email" class="text-red-500 text-sm mt-1">{{ profileForm.errors.email }}</p>
              </div>

              <!-- CPF -->
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">CPF *</label>
                <input
                  v-model="profileForm.cpf"
                  type="text"
                  required
                  placeholder="000.000.000-00"
                  @input="formatCPF"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                  :class="{ 'border-red-500': profileForm.errors.cpf }"
                />
                <p v-if="profileForm.errors.cpf" class="text-red-500 text-sm mt-1">{{ profileForm.errors.cpf }}</p>
              </div>

              <!-- Telefone -->
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Telefone</label>
                <input
                  v-model="profileForm.phone"
                  type="text"
                  placeholder="(11) 99999-9999"
                  @input="formatPhone"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                  :class="{ 'border-red-500': profileForm.errors.phone }"
                />
                <p v-if="profileForm.errors.phone" class="text-red-500 text-sm mt-1">{{ profileForm.errors.phone }}</p>
              </div>

              <!-- Data de Nascimento -->
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Data de Nascimento</label>
                <input
                  v-model="profileForm.birth_date"
                  type="date"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                  :class="{ 'border-red-500': profileForm.errors.birth_date }"
                />
                <p v-if="profileForm.errors.birth_date" class="text-red-500 text-sm mt-1">{{ profileForm.errors.birth_date }}</p>
              </div>

              <!-- Profissão -->
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Profissão</label>
                <input
                  v-model="profileForm.profession"
                  type="text"
                  placeholder="Ex: Desenvolvedor, Contador, etc."
                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                  :class="{ 'border-red-500': profileForm.errors.profession }"
                />
                <p v-if="profileForm.errors.profession" class="text-red-500 text-sm mt-1">{{ profileForm.errors.profession }}</p>
              </div>
            </div>

            <!-- Endereço -->
            <div class="mt-6">
              <h4 class="text-md font-medium text-gray-900 mb-4">Endereço</h4>
              <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-2">CEP</label>
                  <input
                    v-model="profileForm.address.zipcode"
                    type="text"
                    placeholder="00000-000"
                    @input="formatZipcode"
                    @blur="fetchAddressByCEP"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>
                <div class="md:col-span-2">
                  <label class="block text-sm font-medium text-gray-700 mb-2">Rua</label>
                  <input
                    v-model="profileForm.address.street"
                    type="text"
                    placeholder="Nome da rua"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-2">Número</label>
                  <input
                    v-model="profileForm.address.number"
                    type="text"
                    placeholder="123"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-2">Complemento</label>
                  <input
                    v-model="profileForm.address.complement"
                    type="text"
                    placeholder="Apto, Bloco, etc."
                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-2">Bairro</label>
                  <input
                    v-model="profileForm.address.neighborhood"
                    type="text"
                    placeholder="Nome do bairro"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-2">Cidade</label>
                  <input
                    v-model="profileForm.address.city"
                    type="text"
                    placeholder="Nome da cidade"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-2">Estado</label>
                  <select
                    v-model="profileForm.address.state"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                  >
                    <option value="">Selecione</option>
                    <option v-for="state in brazilianStates" :key="state.code" :value="state.code">{{ state.name }}</option>
                  </select>
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-2">País</label>
                  <input
                    v-model="profileForm.address.country"
                    type="text"
                    value="Brasil"
                    readonly
                    class="w-full px-3 py-2 border border-gray-300 rounded-md bg-gray-50"
                  />
                </div>
              </div>
            </div>

            <!-- Botões -->
            <div class="flex justify-end space-x-3 mt-6 pt-6 border-t border-gray-200">
              <button
                type="button"
                @click="resetForm"
                class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg font-medium transition-colors"
              >
                Cancelar
              </button>
              <button
                type="submit"
                :disabled="profileForm.processing"
                class="bg-blue-600 hover:bg-blue-700 disabled:bg-gray-400 text-white px-4 py-2 rounded-lg font-medium transition-colors"
              >
                <svg v-if="profileForm.processing" class="w-4 h-4 inline mr-2 animate-spin" fill="none" viewBox="0 0 24 24">
                  <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                  <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                {{ profileForm.processing ? 'Salvando...' : 'Salvar Alterações' }}
              </button>
            </div>
          </form>
        </div>

        <!-- Alterar Senha -->
        <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
          <div class="p-6 border-b border-gray-200">
            <h3 class="text-lg font-medium text-gray-900">Alterar Senha</h3>
            <p class="text-gray-600 text-sm mt-1">Mantenha sua conta segura com uma senha forte.</p>
          </div>
          
          <form @submit.prevent="updatePassword" class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
              <!-- Senha Atual -->
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Senha Atual *</label>
                <input
                  v-model="passwordForm.current_password"
                  type="password"
                  required
                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                  :class="{ 'border-red-500': passwordForm.errors.current_password }"
                />
                <p v-if="passwordForm.errors.current_password" class="text-red-500 text-sm mt-1">{{ passwordForm.errors.current_password }}</p>
              </div>

              <!-- Nova Senha -->
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Nova Senha *</label>
                <input
                  v-model="passwordForm.password"
                  type="password"
                  required
                  @input="checkPasswordStrength"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                  :class="{ 'border-red-500': passwordForm.errors.password }"
                />
                <p v-if="passwordForm.errors.password" class="text-red-500 text-sm mt-1">{{ passwordForm.errors.password }}</p>
                
                <!-- Indicador de Força da Senha -->
                <div v-if="passwordForm.password" class="mt-2">
                  <div class="flex space-x-1">
                    <div v-for="i in 4" :key="i" 
                         :class="getPasswordStrengthColor(i)" 
                         class="h-1 flex-1 rounded"></div>
                  </div>
                  <p class="text-xs mt-1" :class="passwordStrength.color">{{ passwordStrength.text }}</p>
                </div>
              </div>

              <!-- Confirmar Nova Senha -->
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Confirmar Nova Senha *</label>
                <input
                  v-model="passwordForm.password_confirmation"
                  type="password"
                  required
                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                  :class="{ 'border-red-500': passwordForm.errors.password_confirmation }"
                />
                <p v-if="passwordForm.errors.password_confirmation" class="text-red-500 text-sm mt-1">{{ passwordForm.errors.password_confirmation }}</p>
              </div>
            </div>

            <!-- Dicas de Segurança -->
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mt-4">
              <h4 class="text-sm font-medium text-blue-800 mb-2">Dicas para uma senha segura:</h4>
              <ul class="text-sm text-blue-700 space-y-1">
                <li>• Use pelo menos 8 caracteres</li>
                <li>• Inclua letras maiúsculas e minúsculas</li>
                <li>• Adicione números e símbolos</li>
                <li>• Evite informações pessoais</li>
              </ul>
            </div>

            <!-- Botões -->
            <div class="flex justify-end space-x-3 mt-6 pt-6 border-t border-gray-200">
              <button
                type="button"
                @click="resetPasswordForm"
                class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg font-medium transition-colors"
              >
                Cancelar
              </button>
              <button
                type="submit"
                :disabled="passwordForm.processing"
                class="bg-blue-600 hover:bg-blue-700 disabled:bg-gray-400 text-white px-4 py-2 rounded-lg font-medium transition-colors"
              >
                <svg v-if="passwordForm.processing" class="w-4 h-4 inline mr-2 animate-spin" fill="none" viewBox="0 0 24 24">
                  <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                  <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                {{ passwordForm.processing ? 'Alterando...' : 'Alterar Senha' }}
              </button>
            </div>
          </form>
        </div>

        <!-- Configurações de Notificação -->
        <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
          <div class="p-6 border-b border-gray-200">
            <h3 class="text-lg font-medium text-gray-900">Configurações de Notificação</h3>
            <p class="text-gray-600 text-sm mt-1">Escolha como você quer receber notificações.</p>
          </div>
          
          <div class="p-6">
            <div class="space-y-4">
              <div class="flex items-center justify-between">
                <div>
                  <h4 class="text-sm font-medium text-gray-900">Notificações por Email</h4>
                  <p class="text-sm text-gray-600">Receba atualizações importantes por email</p>
                </div>
                <label class="relative inline-flex items-center cursor-pointer">
                  <input v-model="notificationSettings.email_notifications" type="checkbox" class="sr-only peer">
                  <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                </label>
              </div>

              <div class="flex items-center justify-between">
                <div>
                  <h4 class="text-sm font-medium text-gray-900">Alertas de Preço</h4>
                  <p class="text-sm text-gray-600">Notificações quando preços atingem seus alvos</p>
                </div>
                <label class="relative inline-flex items-center cursor-pointer">
                  <input v-model="notificationSettings.price_alerts" type="checkbox" class="sr-only peer">
                  <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                </label>
              </div>

              <div class="flex items-center justify-between">
                <div>
                  <h4 class="text-sm font-medium text-gray-900">Relatórios Automáticos</h4>
                  <p class="text-sm text-gray-600">Receba relatórios mensais automaticamente</p>
                </div>
                <label class="relative inline-flex items-center cursor-pointer">
                  <input v-model="notificationSettings.automatic_reports" type="checkbox" class="sr-only peer">
                  <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                </label>
              </div>

              <div class="flex items-center justify-between">
                <div>
                  <h4 class="text-sm font-medium text-gray-900">Trading Bot</h4>
                  <p class="text-sm text-gray-600">Notificações sobre atividade do bot de trading</p>
                </div>
                <label class="relative inline-flex items-center cursor-pointer">
                  <input v-model="notificationSettings.trading_bot_alerts" type="checkbox" class="sr-only peer">
                  <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                </label>
              </div>
            </div>

            <div class="flex justify-end mt-6 pt-6 border-t border-gray-200">
              <button
                @click="updateNotificationSettings"
                class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-medium transition-colors"
              >
                Salvar Configurações
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
  user: Object,
  notificationSettings: Object
})

// Forms
const profileForm = useForm({
  name: props.user.name || '',
  email: props.user.email || '',
  cpf: props.user.cpf || '',
  phone: props.user.phone || '',
  birth_date: props.user.birth_date || '',
  profession: props.user.profession || '',
  address: {
    zipcode: props.user.address?.zipcode || '',
    street: props.user.address?.street || '',
    number: props.user.address?.number || '',
    complement: props.user.address?.complement || '',
    neighborhood: props.user.address?.neighborhood || '',
    city: props.user.address?.city || '',
    state: props.user.address?.state || '',
    country: 'Brasil'
  }
})

const passwordForm = useForm({
  current_password: '',
  password: '',
  password_confirmation: ''
})

const notificationSettings = ref({
  email_notifications: props.notificationSettings?.email_notifications || true,
  price_alerts: props.notificationSettings?.price_alerts || false,
  automatic_reports: props.notificationSettings?.automatic_reports || false,
  trading_bot_alerts: props.notificationSettings?.trading_bot_alerts || true
})

const passwordStrength = ref({ level: 0, text: '', color: '' })

const brazilianStates = [
  { code: 'AC', name: 'Acre' },
  { code: 'AL', name: 'Alagoas' },
  { code: 'AP', name: 'Amapá' },
  { code: 'AM', name: 'Amazonas' },
  { code: 'BA', name: 'Bahia' },
  { code: 'CE', name: 'Ceará' },
  { code: 'DF', name: 'Distrito Federal' },
  { code: 'ES', name: 'Espírito Santo' },
  { code: 'GO', name: 'Goiás' },
  { code: 'MA', name: 'Maranhão' },
  { code: 'MT', name: 'Mato Grosso' },
  { code: 'MS', name: 'Mato Grosso do Sul' },
  { code: 'MG', name: 'Minas Gerais' },
  { code: 'PA', name: 'Pará' },
  { code: 'PB', name: 'Paraíba' },
  { code: 'PR', name: 'Paraná' },
  { code: 'PE', name: 'Pernambuco' },
  { code: 'PI', name: 'Piauí' },
  { code: 'RJ', name: 'Rio de Janeiro' },
  { code: 'RN', name: 'Rio Grande do Norte' },
  { code: 'RS', name: 'Rio Grande do Sul' },
  { code: 'RO', name: 'Rondônia' },
  { code: 'RR', name: 'Roraima' },
  { code: 'SC', name: 'Santa Catarina' },
  { code: 'SP', name: 'São Paulo' },
  { code: 'SE', name: 'Sergipe' },
  { code: 'TO', name: 'Tocantins' }
]

// Methods
const formatCPF = (event) => {
  let value = event.target.value.replace(/\D/g, '')
  value = value.replace(/(\d{3})(\d)/, '$1.$2')
  value = value.replace(/(\d{3})(\d)/, '$1.$2')
  value = value.replace(/(\d{3})(\d{1,2})$/, '$1-$2')
  profileForm.cpf = value
}

const formatPhone = (event) => {
  let value = event.target.value.replace(/\D/g, '')
  value = value.replace(/(\d{2})(\d)/, '($1) $2')
  value = value.replace(/(\d{5})(\d)/, '$1-$2')
  profileForm.phone = value
}

const formatZipcode = (event) => {
  let value = event.target.value.replace(/\D/g, '')
  value = value.replace(/(\d{5})(\d)/, '$1-$2')
  profileForm.address.zipcode = value
}

const fetchAddressByCEP = async () => {
  const cep = profileForm.address.zipcode.replace(/\D/g, '')
  if (cep.length === 8) {
    try {
      const response = await fetch(`https://viacep.com.br/ws/${cep}/json/`)
      const data = await response.json()
      if (!data.erro) {
        profileForm.address.street = data.logradouro
        profileForm.address.neighborhood = data.bairro
        profileForm.address.city = data.localidade
        profileForm.address.state = data.uf
      }
    } catch (error) {
      console.error('Erro ao buscar CEP:', error)
    }
  }
}

const checkPasswordStrength = () => {
  const password = passwordForm.password
  let level = 0
  
  if (password.length >= 8) level++
  if (/[a-z]/.test(password) && /[A-Z]/.test(password)) level++
  if (/\d/.test(password)) level++
  if (/[^a-zA-Z\d]/.test(password)) level++
  
  const levels = [
    { text: 'Muito fraca', color: 'text-red-600' },
    { text: 'Fraca', color: 'text-orange-600' },
    { text: 'Média', color: 'text-yellow-600' },
    { text: 'Forte', color: 'text-green-600' },
    { text: 'Muito forte', color: 'text-green-700' }
  ]
  
  passwordStrength.value = { level, ...levels[level] }
}

const getPasswordStrengthColor = (index) => {
  const level = passwordStrength.value.level
  if (index <= level) {
    if (level <= 1) return 'bg-red-500'
    if (level === 2) return 'bg-yellow-500'
    if (level === 3) return 'bg-green-500'
    return 'bg-green-600'
  }
  return 'bg-gray-200'
}

const updateProfile = () => {
  profileForm.put(route('profile.update'), {
    preserveScroll: true
  })
}

const updatePassword = () => {
  passwordForm.put(route('password.update'), {
    preserveScroll: true,
    onSuccess: () => {
      passwordForm.reset()
    }
  })
}

const updateNotificationSettings = async () => {
  try {
    await fetch(route('profile.notification-settings'), {
      method: 'PUT',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
      },
      body: JSON.stringify(notificationSettings.value)
    })
    alert('Configurações salvas com sucesso!')
  } catch (error) {
    console.error('Erro ao salvar configurações:', error)
  }
}

const resetForm = () => {
  profileForm.reset()
}

const resetPasswordForm = () => {
  passwordForm.reset()
}
</script>

