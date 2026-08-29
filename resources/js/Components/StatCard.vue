<template>
  <div class="bg-white shadow rounded-lg p-4">
    <div class="flex items-start">
      <div class="flex-shrink-0">
        <div class="w-8 h-8 bg-indigo-500 rounded-md flex items-center justify-center">
          <component :is="iconComponent" class="w-5 h-5 text-white" />
        </div>
      </div>
      <div class="ml-4 min-w-0 flex-1">
        <dl>
          <dt class="min-h-10 text-sm font-medium leading-5 text-gray-500">
            {{ title }}
          </dt>
          <dd class="mt-1 flex flex-wrap items-baseline gap-x-2 gap-y-1">
            <div class="whitespace-nowrap text-2xl font-semibold tabular-nums text-gray-900">
              {{ formattedValue }}
            </div>
            <div 
              v-if="change !== null" 
              class="flex items-center whitespace-nowrap text-sm font-semibold tabular-nums"
              :class="changeColor"
            >
              <component :is="changeIcon" class="self-center w-4 h-4" />
              <span class="ml-1">{{ formattedChange }}</span>
            </div>
          </dd>
          <dt v-if="subtitle" class="text-xs text-gray-400 mt-1">{{ subtitle }}</dt>
        </dl>
      </div>
    </div>
  </div>
</template>



<script setup>
import { computed } from 'vue'

import CurrencyDollarIcon from '@/Components/Icons/CurrencyDollarIcon.vue'
import ChartBarIcon from '@/Components/Icons/ChartBarIcon.vue'
import TrendingUpIcon from '@/Components/Icons/TrendingUpIcon.vue'
import TrendingDownIcon from '@/Components/Icons/TrendingDownIcon.vue'
import UserGroupIcon from '@/Components/Icons/UserGroupIcon.vue'
import ClockIcon from '@/Components/Icons/ClockIcon.vue'
import ExclamationTriangleIcon from '@/Components/Icons/ExclamationTriangleIcon.vue'

const props = defineProps({
  title: {
    type: String,
    required: true
  },
  value: {
    type: [String, Number],
    required: true
  },
  icon: {
    type: String,
    default: 'chart'
  },
  change: {
    type: Number,
    default: null
  },
  subtitle: {
    type: String,
    default: null
  },
  format: {
    type: String,
    default: 'number' // 'number', 'currency', 'percentage', 'text'
  }
})

const iconComponent = computed(() => {
  const icons = {
    currency: CurrencyDollarIcon,
    chart: ChartBarIcon,
    users: UserGroupIcon,
    clock: ClockIcon,
    warning: ExclamationTriangleIcon
  }
  return icons[props.icon] || ChartBarIcon
})

const formattedValue = computed(() => {
  if (props.format === 'text') {
    return String(props.value ?? '-')
  }

  const numericValue = Number(props.value)

  if (!Number.isFinite(numericValue)) {
    return props.format === 'percentage' ? '0%' : '0'
  }

  if (props.format === 'currency') {
    return new Intl.NumberFormat('pt-BR', {
      style: 'currency',
      currency: 'BRL'
    }).format(numericValue)
  } else if (props.format === 'percentage') {
    return `${numericValue}%`
  } else {
    return new Intl.NumberFormat('pt-BR').format(numericValue)
  }
})

const formattedChange = computed(() => {
  const numericChange = Number(props.change)
  if (!Number.isFinite(numericChange)) return '—'

  return `${new Intl.NumberFormat('pt-BR', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2
  }).format(Math.abs(numericChange))}%`
})

const changeColor = computed(() => {
  if (props.change === null) return ''
  return props.change >= 0 ? 'text-green-600' : 'text-red-600'
})

const changeIcon = computed(() => {
  if (props.change === null) return null
  return props.change >= 0 ? TrendingUpIcon : TrendingDownIcon
})
</script>
