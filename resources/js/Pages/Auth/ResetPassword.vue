<template>
  <div class="min-h-screen flex items-center justify-center bg-gray-50 py-12 px-4 sm:px-6 lg:px-8">
    <div class="max-w-md w-full space-y-8">
      
      <!-- Header -->
      <div>
        <div class="mx-auto h-12 w-auto flex justify-center">
          <h1 class="text-3xl font-bold text-primary">Crypto Tax Manager</h1>
        </div>
        <h2 class="mt-6 text-center text-3xl font-bold text-gray-900">
          Redefinir senha
        </h2>
        <p class="mt-2 text-center text-sm text-gray-600">
          Digite sua nova senha abaixo
        </p>
      </div>

      <!-- Form -->
      <form class="mt-8 space-y-6" @submit.prevent="submit">
        
        <!-- Email (readonly) -->
        <div>
          <label for="email" class="sr-only">Email</label>
          <input
            id="email"
            v-model="form.email"
            name="email"
            type="email"
            autocomplete="email"
            readonly
            class="relative block w-full px-3 py-2 border border-gray-300 placeholder-gray-500 text-gray-900 bg-gray-50 rounded-md focus:outline-none sm:text-sm"
            placeholder="Endereço de email"
          />
          <div v-if="form.errors.email" class="mt-1 text-sm text-red-600">
            {{ form.errors.email }}
          </div>
        </div>

        <!-- Password -->
        <div>
          <label for="password" class="sr-only">Nova senha</label>
          <input
            id="password"
            v-model="form.password"
            name="password"
            type="password"
            autocomplete="new-password"
            required
            class="relative block w-full px-3 py-2 border border-gray-300 placeholder-gray-500 text-gray-900 rounded-md focus:outline-none focus:ring-primary focus:border-primary focus:z-10 sm:text-sm"
            placeholder="Nova senha (mínimo 8 caracteres)"
          />
          <div v-if="form.errors.password" class="mt-1 text-sm text-red-600">
            {{ form.errors.password }}
          </div>
        </div>

        <!-- Confirm Password -->
        <div>
          <label for="password_confirmation" class="sr-only">Confirmar nova senha</label>
          <input
            id="password_confirmation"
            v-model="form.password_confirmation"
            name="password_confirmation"
            type="password"
            autocomplete="new-password"
            required
            class="relative block w-full px-3 py-2 border border-gray-300 placeholder-gray-500 text-gray-900 rounded-md focus:outline-none focus:ring-primary focus:border-primary focus:z-10 sm:text-sm"
            placeholder="Confirmar nova senha"
          />
          <div v-if="form.errors.password_confirmation" class="mt-1 text-sm text-red-600">
            {{ form.errors.password_confirmation }}
          </div>
        </div>

        <!-- Password Strength Indicator -->
        <div v-if="form.password" class="space-y-2">
          <div class="text-sm font-medium text-gray-700">Força da senha:</div>
          <div class="flex space-x-1">
            <div 
              v-for="i in 4" 
              :key="i"
              class="flex-1 h-2 rounded-full"
              :class="getPasswordStrengthColor(i)"
            ></div>
          </div>
          <div class="text-xs text-gray-600">
            {{ getPasswordStrengthText() }}
          </div>
        </div>

        <!-- Submit Button -->
        <div>
          <button
            type="submit"
            :disabled="form.processing"
            class="group relative w-full flex justify-center py-2 px-4 border border-transparent text-sm font-medium rounded-md text-white bg-primary hover:bg-primary-dark focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary disabled:opacity-50 disabled:cursor-not-allowed"
          >
            <span v-if="form.processing" class="absolute left-0 inset-y-0 flex items-center pl-3">
              <svg class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
            </span>
            {{ form.processing ? 'Redefinindo...' : 'Redefinir senha' }}
          </button>
        </div>

        <!-- Error Message -->
        <div v-if="form.errors.general" class="rounded-md bg-red-50 p-4">
          <div class="text-sm text-red-700">
            {{ form.errors.general }}
          </div>
        </div>

      </form>

      <!-- Security Tips -->
      <div class="bg-yellow-50 border border-yellow-200 rounded-md p-4">
        <div class="flex">
          <div class="flex-shrink-0">
            <svg class="h-5 w-5 text-yellow-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
              <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
            </svg>
          </div>
          <div class="ml-3">
            <h3 class="text-sm font-medium text-yellow-800">
              Dicas de segurança:
            </h3>
            <div class="mt-2 text-sm text-yellow-700">
              <ul class="list-disc list-inside space-y-1">
                <li>Use pelo menos 8 caracteres</li>
                <li>Combine letras maiúsculas e minúsculas</li>
                <li>Inclua números e símbolos</li>
                <li>Evite informações pessoais</li>
              </ul>
            </div>
          </div>
        </div>
      </div>

    </div>
  </div>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3'
import { computed } from 'vue'

// Props
const props = defineProps({
  token: String,
  email: String,
})

// Form
const form = useForm({
  token: props.token,
  email: props.email,
  password: '',
  password_confirmation: '',
})

// Password strength calculation
const passwordStrength = computed(() => {
  const password = form.password
  if (!password) return 0
  
  let strength = 0
  
  // Length
  if (password.length >= 8) strength++
  if (password.length >= 12) strength++
  
  // Character types
  if (/[a-z]/.test(password) && /[A-Z]/.test(password)) strength++
  if (/\d/.test(password)) strength++
  if (/[^A-Za-z0-9]/.test(password)) strength++
  
  return Math.min(strength, 4)
})

// Methods
const submit = () => {
  form.post('/reset-password', {
    onFinish: () => form.reset('password', 'password_confirmation'),
  })
}

const getPasswordStrengthColor = (index) => {
  if (index <= passwordStrength.value) {
    if (passwordStrength.value <= 1) return 'bg-red-400'
    if (passwordStrength.value <= 2) return 'bg-yellow-400'
    if (passwordStrength.value <= 3) return 'bg-blue-400'
    return 'bg-green-400'
  }
  return 'bg-gray-200'
}

const getPasswordStrengthText = () => {
  if (passwordStrength.value <= 1) return 'Fraca'
  if (passwordStrength.value <= 2) return 'Regular'
  if (passwordStrength.value <= 3) return 'Boa'
  return 'Forte'
}
</script>

<style scoped>
.bg-primary {
  background-color: var(--primary-color, #3b82f6);
}

.text-primary {
  color: var(--primary-color, #3b82f6);
}

.border-primary {
  border-color: var(--primary-color, #3b82f6);
}

.ring-primary {
  --tw-ring-color: var(--primary-color, #3b82f6);
}

.hover\\:bg-primary-dark:hover {
  background-color: var(--primary-dark, #2563eb);
}

.focus\\:ring-primary:focus {
  --tw-ring-color: var(--primary-color, #3b82f6);
}

.focus\\:border-primary:focus {
  border-color: var(--primary-color, #3b82f6);
}
</style>

