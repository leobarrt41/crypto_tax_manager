<template>
  <div class="relative group bg-white p-6 focus-within:ring-2 focus-within:ring-inset focus-within:ring-indigo-500 rounded-lg shadow hover:shadow-md transition-shadow duration-200">
    <div>
      <span class="rounded-lg inline-flex p-3 ring-4 ring-white" :class="iconBgColor">
        <component :is="iconComponent" class="h-6 w-6" :class="iconColor" />
      </span>
    </div>
    <div class="mt-8">
      <h3 class="text-lg font-medium">
        <component 
          :is="href ? 'Link' : 'button'"
          :href="href"
          @click="handleClick"
          class="focus:outline-none"
        >
          <span class="absolute inset-0" aria-hidden="true"></span>
          {{ title }}
        </component>
      </h3>
      <p class="mt-2 text-sm text-gray-500">
        {{ description }}
      </p>
    </div>
    <span 
      class="pointer-events-none absolute top-6 right-6 text-gray-300 group-hover:text-gray-400 transition-colors duration-200" 
      aria-hidden="true"
    >
      <svg class="h-6 w-6" fill="currentColor" viewBox="0 0 24 24">
        <path d="m11.293 17.293 1.414 1.414L19.414 12l-6.707-6.707-1.414 1.414L15.586 11H6v2h9.586l-4.293 4.293Z"/>
      </svg>
    </span>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { 
  PlusIcon,
  DocumentArrowDownIcon,
  ChartBarIcon,
  CogIcon,
  BanknotesIcon,
  ArrowPathIcon,
  PlayIcon,
  StopIcon,
  EyeIcon,
  DocumentTextIcon
} from '@heroicons/vue/24/outline'

const props = defineProps({
  title: {
    type: String,
    required: true
  },
  description: {
    type: String,
    required: true
  },
  icon: {
    type: String,
    default: 'plus'
  },
  color: {
    type: String,
    default: 'indigo' // 'indigo', 'green', 'blue', 'yellow', 'red', 'purple'
  },
  href: {
    type: String,
    default: null
  },
  action: {
    type: Function,
    default: null
  }
})

const emit = defineEmits(['click'])

const iconComponent = computed(() => {
  const icons = {
    plus: PlusIcon,
    download: DocumentArrowDownIcon,
    chart: ChartBarIcon,
    settings: CogIcon,
    money: BanknotesIcon,
    refresh: ArrowPathIcon,
    play: PlayIcon,
    stop: StopIcon,
    view: EyeIcon,
    document: DocumentTextIcon
  }
  return icons[props.icon] || PlusIcon
})

const iconBgColor = computed(() => {
  const colors = {
    indigo: 'bg-indigo-50',
    green: 'bg-green-50',
    blue: 'bg-blue-50',
    yellow: 'bg-yellow-50',
    red: 'bg-red-50',
    purple: 'bg-purple-50'
  }
  return colors[props.color] || 'bg-indigo-50'
})

const iconColor = computed(() => {
  const colors = {
    indigo: 'text-indigo-600',
    green: 'text-green-600',
    blue: 'text-blue-600',
    yellow: 'text-yellow-600',
    red: 'text-red-600',
    purple: 'text-purple-600'
  }
  return colors[props.color] || 'text-indigo-600'
})

const handleClick = () => {
  if (props.action) {
    props.action()
  }
  emit('click')
}
</script>

