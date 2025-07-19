import { useState, useEffect } from 'react'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { 
  TrendingUp, 
  TrendingDown, 
  DollarSign, 
  ArrowRightLeft, 
  AlertTriangle,
  FileText,
  Plus
} from 'lucide-react'
import { LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer, PieChart, Pie, Cell } from 'recharts'

const COLORS = ['#0088FE', '#00C49F', '#FFBB28', '#FF8042', '#8884D8']

export function Dashboard() {
  const [stats, setStats] = useState(null)
  const [loading, setLoading] = useState(true)
  const [portfolioData, setPortfolioData] = useState(null)

  useEffect(() => {
    fetchDashboardData()
  }, [])

  const fetchDashboardData = async () => {
    try {
      setLoading(true)
      
      // Fetch dashboard stats
      const statsResponse = await fetch('/api/dashboard/stats?user_id=1')
      const statsData = await statsResponse.json()
      
      // Fetch portfolio summary
      const portfolioResponse = await fetch('/api/portfolio/summary?user_id=1')
      const portfolioData = await portfolioResponse.json()
      
      if (statsData.success) {
        setStats(statsData.data)
      }
      
      if (portfolioData.success) {
        setPortfolioData(portfolioData)
      }
      
    } catch (error) {
      console.error('Error fetching dashboard data:', error)
    } finally {
      setLoading(false)
    }
  }

  const createSampleData = async () => {
    try {
      // Create sample exchanges and assets
      await fetch('/api/sample-data/create', { method: 'POST' })
      
      // Create sample transactions
      await fetch('/api/sample-data/transactions', { 
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ user_id: 1 })
      })
      
      // Refresh dashboard data
      fetchDashboardData()
    } catch (error) {
      console.error('Error creating sample data:', error)
    }
  }

  if (loading) {
    return (
      <div className="space-y-6">
        <div className="flex items-center justify-between">
          <h1 className="text-3xl font-bold text-foreground">Dashboard</h1>
        </div>
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
          {[...Array(4)].map((_, i) => (
            <Card key={i} className="animate-pulse">
              <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                <div className="h-4 bg-muted rounded w-20"></div>
                <div className="h-4 w-4 bg-muted rounded"></div>
              </CardHeader>
              <CardContent>
                <div className="h-8 bg-muted rounded w-24 mb-2"></div>
                <div className="h-3 bg-muted rounded w-32"></div>
              </CardContent>
            </Card>
          ))}
        </div>
      </div>
    )
  }

  // Sample chart data
  const chartData = [
    { month: 'Jan', value: 35000 },
    { month: 'Fev', value: 42000 },
    { month: 'Mar', value: 38000 },
    { month: 'Abr', value: 55000 },
    { month: 'Mai', value: 67000 },
    { month: 'Jun', value: 45000 },
  ]

  const pieData = portfolioData?.assets ? Object.entries(portfolioData.assets).map(([asset, data]) => ({
    name: asset,
    value: data.current_value_brl,
    percentage: ((data.current_value_brl / portfolioData.total_current_value_brl) * 100).toFixed(1)
  })) : []

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold text-foreground">Dashboard</h1>
          <p className="text-muted-foreground">
            Visão geral das suas operações com criptomoedas
          </p>
        </div>
        {(!stats || stats.total_transactions === 0) && (
          <Button onClick={createSampleData} className="flex items-center gap-2">
            <Plus className="h-4 w-4" />
            Criar Dados de Exemplo
          </Button>
        )}
      </div>

      {/* Stats Cards */}
      <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">Total de Transações</CardTitle>
            <ArrowRightLeft className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">{stats?.total_transactions || 0}</div>
            <p className="text-xs text-muted-foreground">
              {stats?.month_transactions || 0} este mês
            </p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">Volume Total</CardTitle>
            <DollarSign className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">
              R$ {(stats?.total_volume_brl || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2 })}
            </div>
            <p className="text-xs text-muted-foreground">
              R$ {(stats?.month_volume_brl || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2 })} este mês
            </p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">Portfólio Atual</CardTitle>
            <TrendingUp className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">
              R$ {(portfolioData?.total_current_value_brl || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2 })}
            </div>
            <p className="text-xs text-muted-foreground">
              {portfolioData?.assets_count || 0} ativos diferentes
            </p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">Status IN 1888</CardTitle>
            {stats?.needs_in1888_declaration ? (
              <AlertTriangle className="h-4 w-4 text-yellow-500" />
            ) : (
              <FileText className="h-4 w-4 text-muted-foreground" />
            )}
          </CardHeader>
          <CardContent>
            <div className="flex items-center space-x-2">
              <Badge variant={stats?.needs_in1888_declaration ? "destructive" : "secondary"}>
                {stats?.needs_in1888_declaration ? "Declarar" : "Isento"}
              </Badge>
            </div>
            <p className="text-xs text-muted-foreground mt-1">
              {stats?.needs_in1888_declaration 
                ? "Volume mensal > R$ 30.000" 
                : "Volume mensal < R$ 30.000"
              }
            </p>
          </CardContent>
        </Card>
      </div>

      {/* Charts */}
      <div className="grid gap-4 md:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>Volume Mensal</CardTitle>
            <CardDescription>
              Evolução do volume de transações por mês
            </CardDescription>
          </CardHeader>
          <CardContent>
            <ResponsiveContainer width="100%" height={300}>
              <LineChart data={chartData}>
                <CartesianGrid strokeDasharray="3 3" />
                <XAxis dataKey="month" />
                <YAxis />
                <Tooltip 
                  formatter={(value) => [`R$ ${value.toLocaleString('pt-BR')}`, 'Volume']}
                />
                <Line 
                  type="monotone" 
                  dataKey="value" 
                  stroke="#8884d8" 
                  strokeWidth={2}
                />
              </LineChart>
            </ResponsiveContainer>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Distribuição do Portfólio</CardTitle>
            <CardDescription>
              Alocação atual por criptoativo
            </CardDescription>
          </CardHeader>
          <CardContent>
            {pieData.length > 0 ? (
              <ResponsiveContainer width="100%" height={300}>
                <PieChart>
                  <Pie
                    data={pieData}
                    cx="50%"
                    cy="50%"
                    labelLine={false}
                    label={({ name, percentage }) => `${name} ${percentage}%`}
                    outerRadius={80}
                    fill="#8884d8"
                    dataKey="value"
                  >
                    {pieData.map((entry, index) => (
                      <Cell key={`cell-${index}`} fill={COLORS[index % COLORS.length]} />
                    ))}
                  </Pie>
                  <Tooltip 
                    formatter={(value) => [`R$ ${value.toLocaleString('pt-BR')}`, 'Valor']}
                  />
                </PieChart>
              </ResponsiveContainer>
            ) : (
              <div className="flex items-center justify-center h-[300px] text-muted-foreground">
                Nenhum ativo no portfólio
              </div>
            )}
          </CardContent>
        </Card>
      </div>

      {/* Recent Transactions */}
      <Card>
        <CardHeader>
          <CardTitle>Transações Recentes</CardTitle>
          <CardDescription>
            Últimas 5 transações realizadas
          </CardDescription>
        </CardHeader>
        <CardContent>
          {stats?.recent_transactions && stats.recent_transactions.length > 0 ? (
            <div className="space-y-4">
              {stats.recent_transactions.map((transaction) => (
                <div key={transaction.id} className="flex items-center justify-between p-3 border rounded-lg">
                  <div className="flex items-center space-x-3">
                    <div className={`p-2 rounded-full ${
                      transaction.type === 'buy' ? 'bg-green-100 text-green-600' :
                      transaction.type === 'sell' ? 'bg-red-100 text-red-600' :
                      'bg-blue-100 text-blue-600'
                    }`}>
                      <ArrowRightLeft className="h-4 w-4" />
                    </div>
                    <div>
                      <p className="font-medium">
                        {transaction.type === 'buy' ? 'Compra' : 
                         transaction.type === 'sell' ? 'Venda' : 
                         transaction.type === 'swap' ? 'Troca' : transaction.type}
                      </p>
                      <p className="text-sm text-muted-foreground">
                        {transaction.to_asset || transaction.from_asset}
                      </p>
                    </div>
                  </div>
                  <div className="text-right">
                    <p className="font-medium">
                      R$ {(transaction.total_brl || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2 })}
                    </p>
                    <p className="text-sm text-muted-foreground">
                      {new Date(transaction.date).toLocaleDateString('pt-BR')}
                    </p>
                  </div>
                </div>
              ))}
            </div>
          ) : (
            <div className="text-center py-8 text-muted-foreground">
              Nenhuma transação encontrada
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  )
}

