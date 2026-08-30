<template>
  <AppLayout title="Novo backtest">
    <div class="py-8">
      <div class="mx-auto max-w-4xl space-y-6 px-4 sm:px-6 lg:px-8">
        <section class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-amber-950">
          <h1 class="text-2xl font-bold">Novo backtest histórico</h1>
          <p class="mt-2 text-sm leading-6">Esta tela calcula uma simulação com candles fechados. Nenhuma chave de API, saldo ou ordem de exchange será usada. Resultado passado não garante desempenho futuro.</p>
        </section>

        <section v-if="markets.length === 0" class="rounded-xl border border-rose-200 bg-rose-50 p-5 text-rose-900">
          <h2 class="font-semibold">Fonte pública indisponível</h2>
          <p class="mt-1 text-sm">A exchange Binance ainda não está cadastrada na base da aplicação. Não é possível iniciar um backtest até que a configuração pública esteja disponível.</p>
        </section>

        <form v-else class="space-y-6 rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200" @submit.prevent="submit">
          <div>
            <h2 class="text-lg font-semibold text-slate-900">Versão e mercado</h2>
            <p class="mt-1 text-sm text-slate-600">A estratégia continua reutilizável. Par, timeframe e capital pertencem somente a este cenário de backtest.</p>
          </div>

          <div class="grid gap-5 sm:grid-cols-2">
            <label class="block sm:col-span-2">
              <span class="text-sm font-medium text-slate-700">Versão de estratégia</span>
              <select v-model="form.strategy_version_id" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" required>
                <option value="">Selecione uma versão</option>
                <optgroup v-for="strategy in strategies" :key="strategy.id" :label="strategy.name">
                  <option v-for="version in strategy.versions" :key="version.id" :value="String(version.id)">Versão {{ version.version }} · {{ shortHash(version.definition_hash) }}</option>
                </optgroup>
              </select>
              <p v-if="form.errors.strategy_version_id" class="mt-1 text-sm text-rose-600">{{ form.errors.strategy_version_id }}</p>
            </label>

            <label class="block">
              <span class="text-sm font-medium text-slate-700">Exchange pública</span>
              <select v-model="form.exchange_id" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" required>
                <option v-for="market in markets" :key="market.exchange_id" :value="String(market.exchange_id)">{{ market.exchange_label }}</option>
              </select>
            </label>

            <label class="block">
              <span class="text-sm font-medium text-slate-700">Par</span>
              <select v-model="form.symbol" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" required>
                <option v-for="symbol in selectedMarket?.symbols || []" :key="symbol" :value="symbol">{{ symbol }}</option>
              </select>
            </label>

            <label class="block">
              <span class="text-sm font-medium text-slate-700">Timeframe</span>
              <select v-model="form.timeframe" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" required>
                <option v-for="timeframe in selectedMarket?.timeframes || []" :key="timeframe" :value="timeframe">{{ timeframe }}</option>
              </select>
            </label>

            <label class="block">
              <span class="text-sm font-medium text-slate-700">Fuso dos candles</span>
              <input class="mt-1 block w-full rounded-lg border-slate-300 bg-slate-50 text-sm text-slate-600 shadow-sm" :value="defaults.timezone" disabled>
            </label>
          </div>

          <div class="border-t border-slate-200 pt-6">
            <h2 class="text-lg font-semibold text-slate-900">Período e premissas</h2>
            <p class="mt-1 text-sm text-slate-600">O período máximo é de {{ defaults.maximum_period_days }} dias. Taxas e slippage são aplicados tanto à estratégia quanto ao comparativo buy-and-hold.</p>
          </div>

          <div class="grid gap-5 sm:grid-cols-2">
            <label class="block">
              <span class="text-sm font-medium text-slate-700">Início (UTC)</span>
              <input v-model="form.start_at" type="datetime-local" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" required>
              <p v-if="form.errors.start_at" class="mt-1 text-sm text-rose-600">{{ form.errors.start_at }}</p>
            </label>
            <label class="block">
              <span class="text-sm font-medium text-slate-700">Fim (UTC)</span>
              <input v-model="form.end_at" type="datetime-local" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" required>
              <p v-if="form.errors.end_at" class="mt-1 text-sm text-rose-600">{{ form.errors.end_at }}</p>
            </label>
            <label class="block">
              <span class="text-sm font-medium text-slate-700">Capital inicial (USDT)</span>
              <input v-model="form.initial_capital" inputmode="decimal" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" required>
            </label>
            <label class="block">
              <span class="text-sm font-medium text-slate-700">Alocação por entrada (%)</span>
              <input v-model="form.allocation_pct" inputmode="decimal" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" required>
            </label>
            <label class="block">
              <span class="text-sm font-medium text-slate-700">Taxa por lado (%)</span>
              <input v-model="form.fee_rate" inputmode="decimal" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" required>
            </label>
            <label class="block">
              <span class="text-sm font-medium text-slate-700">Slippage por lado (%)</span>
              <input v-model="form.slippage_rate" inputmode="decimal" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" required>
            </label>
          </div>

          <label class="flex items-start gap-3 rounded-lg bg-slate-50 p-4 text-sm text-slate-700">
            <input v-model="form.close_open_position_at_end" type="checkbox" class="mt-0.5 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
            <span><strong>Liquidar posição aberta ao fim.</strong> Se desmarcado, a posição final será marcada a mercado pelo último fechamento disponível.</span>
          </label>

          <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
            <Link :href="route('trading-bot.backtests.index')" class="inline-flex justify-center rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">Cancelar</Link>
            <button type="submit" :disabled="form.processing" class="inline-flex justify-center rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60">{{ form.processing ? 'Calculando…' : 'Executar simulação histórica' }}</button>
          </div>
        </form>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed, watch } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
  strategies: { type: Array, default: () => [] },
  markets: { type: Array, default: () => [] },
  defaults: { type: Object, required: true },
  executionEnabled: { type: Boolean, default: false },
})

const selectedMarket = computed(() => props.markets.find((market) => String(market.exchange_id) === String(form.exchange_id)) || props.markets[0] || null)
const form = useForm({
  strategy_version_id: '',
  exchange_id: props.markets[0] ? String(props.markets[0].exchange_id) : '',
  symbol: props.markets[0]?.symbols?.[0] || '',
  timeframe: props.markets[0]?.timeframes?.[0] || '',
  start_at: '',
  end_at: '',
  initial_capital: props.defaults.initial_capital,
  allocation_pct: props.defaults.allocation_pct,
  fee_rate: props.defaults.fee_rate,
  slippage_rate: props.defaults.slippage_rate,
  close_open_position_at_end: props.defaults.close_open_position_at_end,
})

watch(selectedMarket, (market) => {
  if (!market) return
  if (!market.symbols.includes(form.symbol)) form.symbol = market.symbols[0]
  if (!market.timeframes.includes(form.timeframe)) form.timeframe = market.timeframes[0]
})

const shortHash = (value) => value ? `${value.slice(0, 12)}…` : 'sem hash'
const submit = () => form.post(route('trading-bot.backtests.store'))
</script>
