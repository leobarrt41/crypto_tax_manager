<template>
  <AppLayout :title="isEditing ? 'Editar estratégia' : 'Nova estratégia'">
    <div class="py-8">
      <div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
        <div class="rounded-xl border border-indigo-100 bg-indigo-50 p-5 text-indigo-950">
          <h1 class="text-2xl font-bold">{{ isEditing ? 'Nova versão da estratégia' : 'Criar estratégia' }}</h1>
          <p class="mt-1 text-sm leading-6">
            A regra será salva para backtesting futuro. Par, exchange, timeframe, modo operacional e lado da ordem serão definidos somente em fases posteriores.
          </p>
        </div>

        <form class="space-y-6" @submit.prevent="submit">
          <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
            <h2 class="text-lg font-semibold text-slate-900">1. Identificação</h2>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
              <label class="block">
                <span class="text-sm font-medium text-slate-700">Nome</span>
                <input v-model.trim="form.name" required maxlength="120" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="Ex.: RSI de sobrevenda" />
              </label>
              <label class="block md:col-span-2">
                <span class="text-sm font-medium text-slate-700">Descrição</span>
                <textarea v-model.trim="form.description" rows="3" maxlength="2000" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="Objetivo e premissas da regra."></textarea>
              </label>
            </div>
          </section>

          <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
              <div>
                <h2 class="text-lg font-semibold text-slate-900">2. Condições de sinal</h2>
                <p class="mt-1 text-sm text-slate-600">Apenas candles fechados serão considerados. A ordem das condições é preservada na versão.</p>
              </div>
              <select v-model="form.definition.logic" class="rounded-md border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                <option value="all">Todas as condições (AND)</option>
                <option value="any">Qualquer condição (OR)</option>
              </select>
            </div>

            <div class="mt-5 space-y-4">
              <article v-for="(condition, index) in form.definition.conditions" :key="index" class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                <div class="flex items-center justify-between gap-4">
                  <h3 class="font-semibold text-slate-800">Condição {{ index + 1 }}</h3>
                  <button v-if="form.definition.conditions.length > 1" type="button" class="text-sm font-semibold text-rose-700 hover:text-rose-900" @click="removeCondition(index)">Remover</button>
                </div>

                <div class="mt-4 grid gap-4 md:grid-cols-3">
                  <label class="block">
                    <span class="text-xs font-medium uppercase tracking-wide text-slate-600">Indicador</span>
                    <select v-model="condition.indicator" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm" @change="resetParameters(condition)">
                      <option v-for="indicator in catalog.indicators" :key="indicator.key" :value="indicator.key">{{ indicator.label }}</option>
                    </select>
                  </label>
                  <label class="block">
                    <span class="text-xs font-medium uppercase tracking-wide text-slate-600">Operador</span>
                    <select v-model="condition.operator" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm" @change="resetOperatorValues(condition)">
                      <option v-for="operator in availableOperators(condition.indicator)" :key="operator.value" :value="operator.value">{{ operator.label }}</option>
                    </select>
                  </label>
                  <label v-if="requiresValue(condition.operator)" class="block">
                    <span class="text-xs font-medium uppercase tracking-wide text-slate-600">Valor</span>
                    <input v-model.number="condition.value" type="number" step="any" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm" />
                  </label>
                </div>

                <div class="mt-4 grid gap-4 md:grid-cols-3">
                  <label v-for="parameter in parameterFields(condition.indicator)" :key="parameter.key" class="block">
                    <span class="text-xs font-medium uppercase tracking-wide text-slate-600">{{ parameter.label }}</span>
                    <select v-if="parameter.options" v-model="condition.parameters[parameter.key]" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm">
                      <option v-for="option in parameter.options" :key="option" :value="option">{{ option.toUpperCase() }}</option>
                    </select>
                    <input v-else v-model.number="condition.parameters[parameter.key]" type="number" min="0" step="any" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm" />
                  </label>
                </div>

                <div v-if="isIndicatorComparison(condition.operator)" class="mt-4 rounded-md border border-sky-200 bg-sky-50 p-3">
                  <p class="text-sm font-medium text-sky-900">Comparar com indicador</p>
                  <div class="mt-3 grid gap-4 md:grid-cols-2">
                    <select v-model="condition.compare_with.indicator" class="rounded-md border-slate-300 text-sm shadow-sm" @change="resetComparisonParameters(condition)">
                      <option value="sma">Média móvel simples (SMA)</option>
                      <option value="ema">Média móvel exponencial (EMA)</option>
                      <option value="rsi">RSI</option>
                    </select>
                    <input v-model.number="condition.compare_with.parameters.period" type="number" min="2" max="500" class="rounded-md border-slate-300 text-sm shadow-sm" placeholder="Período" />
                  </div>
                </div>
              </article>
            </div>

            <button type="button" class="mt-4 text-sm font-semibold text-indigo-700 hover:text-indigo-900" @click="addCondition">+ Adicionar condição</button>
            <p class="mt-4 text-sm text-slate-600">Mínimo estimado: <strong>{{ minimumCandles }} candles fechados</strong> para avaliar esta estratégia.</p>
            <p v-if="formErrors.definition" class="mt-2 text-sm text-rose-700">{{ formErrors.definition }}</p>
          </section>

          <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
            <h2 class="text-lg font-semibold text-slate-900">3. Configurações de risco</h2>
            <p class="mt-1 text-sm text-slate-600">São apenas parâmetros cadastrados. Stop-loss, take-profit e trailing stop não são executados nesta fase.</p>
            <div class="mt-4 grid gap-4 md:grid-cols-3">
              <label v-for="risk in riskFields" :key="risk.key" class="block">
                <span class="text-sm font-medium text-slate-700">{{ risk.label }}</span>
                <div class="relative mt-1">
                  <input v-model.number="form.definition.risk[risk.key]" type="number" min="0" max="100" step="0.01" class="block w-full rounded-md border-slate-300 pr-8 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="Opcional" />
                  <span class="absolute inset-y-0 right-3 flex items-center text-sm text-slate-500">%</span>
                </div>
              </label>
            </div>
          </section>

          <section class="rounded-xl border border-slate-200 bg-slate-50 p-5">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-700">Prévia da lógica</h2>
            <p class="mt-2 text-sm leading-6 text-slate-800">{{ logicPreview }}</p>
            <p class="mt-3 text-xs text-slate-500">Esta prévia não consulta exchanges, não executa backtest e não cria operações.</p>
          </section>

          <div v-if="Object.keys(errors).length" class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">
            <p class="font-semibold">Revise os campos indicados:</p>
            <ul class="mt-2 list-disc space-y-1 pl-5"><li v-for="(messages, key) in errors" :key="key">{{ messages[0] }}</li></ul>
          </div>

          <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
            <Link :href="route('trading-bot.strategies.index')" class="rounded-lg border border-slate-300 px-4 py-2.5 text-center text-sm font-semibold text-slate-700 hover:bg-slate-50">Cancelar</Link>
            <button type="submit" :disabled="submitting" class="rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60">
              {{ submitting ? 'Salvando...' : 'Salvar estratégia para backtesting' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed, reactive, ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
  strategy: { type: Object, default: null },
  version: { type: Object, default: null },
  catalog: { type: Object, required: true },
})

const isEditing = computed(() => Boolean(props.strategy))
const submitting = ref(false)
const errors = ref({})
const riskFields = [
  { key: 'stop_loss_pct', label: 'Stop-loss' },
  { key: 'take_profit_pct', label: 'Take-profit' },
  { key: 'trailing_stop_pct', label: 'Trailing stop' },
]

const defaultCondition = () => ({ indicator: 'rsi', parameters: { period: 14 }, operator: 'less_than', value: 30 })
const definition = props.version?.definition || { schema_version: 1, logic: 'all', conditions: [defaultCondition()], risk: {} }
const form = reactive({
  name: props.strategy?.name || '',
  description: props.strategy?.description || '',
  definition: JSON.parse(JSON.stringify(definition)),
})

const formErrors = computed(() => ({ definition: errors.value.conditions?.[0] }))
const parameterFields = (indicator) => ({
  rsi: [{ key: 'period', label: 'Período' }],
  sma: [{ key: 'period', label: 'Período' }],
  ema: [{ key: 'period', label: 'Período' }],
  macd: [{ key: 'fast_period', label: 'Período rápido' }, { key: 'slow_period', label: 'Período lento' }, { key: 'signal_period', label: 'Período do sinal' }, { key: 'component', label: 'Componente', options: ['line', 'signal', 'histogram'] }],
  bollinger: [{ key: 'period', label: 'Período' }, { key: 'std_dev', label: 'Desvios-padrão' }, { key: 'component', label: 'Componente', options: ['middle', 'upper', 'lower'] }],
  moving_average_cross: [{ key: 'fast_period', label: 'Período rápido' }, { key: 'slow_period', label: 'Período lento' }, { key: 'average_type', label: 'Tipo de média', options: ['ema', 'sma'] }],
}[indicator] || [])

const defaults = {
  rsi: { period: 14 }, sma: { period: 20 }, ema: { period: 20 },
  macd: { fast_period: 12, slow_period: 26, signal_period: 9, component: 'line' },
  bollinger: { period: 20, std_dev: 2, component: 'middle' },
  moving_average_cross: { fast_period: 20, slow_period: 50, average_type: 'ema' },
}

const availableOperators = (indicator) => {
  const standard = [
    { value: 'greater_than', label: 'maior que' }, { value: 'less_than', label: 'menor que' },
    { value: 'greater_than_or_equal', label: 'maior ou igual a' }, { value: 'less_than_or_equal', label: 'menor ou igual a' },
    { value: 'crosses_above', label: 'cruza acima de' }, { value: 'crosses_below', label: 'cruza abaixo de' },
    { value: 'greater_than_indicator', label: 'maior que outro indicador' }, { value: 'less_than_indicator', label: 'menor que outro indicador' },
  ]
  if (indicator === 'bollinger') return [...standard, { value: 'close_above_upper_band', label: 'fechamento acima da banda superior' }, { value: 'close_below_lower_band', label: 'fechamento abaixo da banda inferior' }]
  return standard
}

const requiresValue = (operator) => !['greater_than_indicator', 'less_than_indicator', 'close_above_upper_band', 'close_below_lower_band'].includes(operator)
const isIndicatorComparison = (operator) => ['greater_than_indicator', 'less_than_indicator'].includes(operator)
const resetParameters = (condition) => {
  condition.parameters = { ...defaults[condition.indicator] }
  condition.operator = condition.indicator === 'bollinger' ? 'close_below_lower_band' : 'less_than'
  condition.value = condition.indicator === 'rsi' ? 30 : 0
  delete condition.compare_with
}
const resetOperatorValues = (condition) => {
  if (isIndicatorComparison(condition.operator) && !condition.compare_with) condition.compare_with = { indicator: 'ema', parameters: { period: 50 } }
  if (!isIndicatorComparison(condition.operator)) delete condition.compare_with
}
const resetComparisonParameters = (condition) => { condition.compare_with.parameters = { period: 50 } }
const addCondition = () => form.definition.conditions.push(defaultCondition())
const removeCondition = (index) => form.definition.conditions.splice(index, 1)

const minimumCandles = computed(() => Math.max(...form.definition.conditions.map((condition) => {
  const p = condition.parameters
  let required = Number(p.period || 0)
  if (condition.indicator === 'rsi') required = Number(p.period || 0) + 1
  if (condition.indicator === 'macd') required = Number(p.slow_period || 0) + Number(p.signal_period || 0) - 1
  if (condition.indicator === 'moving_average_cross') required = Number(p.slow_period || 0)
  return required + (['crosses_above', 'crosses_below'].includes(condition.operator) ? 1 : 0)
}), 1))

const logicPreview = computed(() => {
  const logic = form.definition.logic === 'all' ? 'todas' : 'qualquer uma'
  const conditions = form.definition.conditions.map((condition) => `${condition.indicator.toUpperCase()} ${condition.operator.replaceAll('_', ' ')}`).join('; ')
  return `A estratégia gera sinal quando ${logic} destas condições forem atendidas na última vela fechada: ${conditions}.`
})

const submit = () => {
  submitting.value = true
  errors.value = {}
  const payload = JSON.parse(JSON.stringify(form))
  Object.entries(payload.definition.risk).forEach(([key, value]) => {
    if (value === '' || value === null || value === undefined) delete payload.definition.risk[key]
  })
  const action = isEditing.value
    ? router.patch(route('trading-bot.strategies.update', props.strategy.id), payload, options())
    : router.post(route('trading-bot.strategies.store'), payload, options())
  return action
}
const options = () => ({
  onError: (validationErrors) => { errors.value = validationErrors },
  onFinish: () => { submitting.value = false },
})
</script>
