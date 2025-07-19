from datetime import datetime, timedelta
from decimal import Decimal, ROUND_HALF_UP
from typing import List, Dict, Any, Tuple
from src.models.crypto_models import Transaction, TaxRule, db
import calendar

class TaxCalculator:
    """
    Calculadora fiscal para criptomoedas conforme legislação brasileira
    """
    
    def __init__(self):
        # Limites e alíquotas conforme legislação brasileira
        self.MONTHLY_EXEMPTION_LIMIT = Decimal('35000.00')  # R$ 35.000 isenção mensal
        self.CAPITAL_GAINS_TAX_RATE = Decimal('0.15')       # 15% sobre ganhos de capital
        self.DAY_TRADE_TAX_RATE = Decimal('0.20')           # 20% para day trade
        self.IN1888_THRESHOLD = Decimal('30000.00')         # R$ 30.000 para declarar IN 1888
    
    def calculate_monthly_tax(self, user_id: int, month: int, year: int) -> Dict[str, Any]:
        """
        Calcula impostos devidos no mês conforme legislação brasileira
        """
        try:
            # Buscar transações do mês
            start_date = datetime(year, month, 1)
            if month == 12:
                end_date = datetime(year + 1, 1, 1) - timedelta(days=1)
            else:
                end_date = datetime(year, month + 1, 1) - timedelta(days=1)
            
            transactions = Transaction.query.filter(
                Transaction.user_id == user_id,
                Transaction.date >= start_date,
                Transaction.date <= end_date,
                Transaction.type.in_(['sell', 'swap'])  # Apenas vendas e trocas geram fato gerador
            ).order_by(Transaction.date).all()
            
            if not transactions:
                return {
                    'success': True,
                    'month': month,
                    'year': year,
                    'total_sales_brl': 0,
                    'total_gains_brl': 0,
                    'tax_due_brl': 0,
                    'is_exempt': True,
                    'needs_in1888': False,
                    'transactions_count': 0
                }
            
            # Calcular vendas totais do mês
            total_sales = sum(
                Decimal(str(t.total_brl)) for t in transactions if t.total_brl
            )
            
            # Verificar se está isento (vendas < R$ 35.000)
            is_exempt = total_sales < self.MONTHLY_EXEMPTION_LIMIT
            
            # Calcular ganhos de capital
            total_gains = Decimal('0')
            day_trade_gains = Decimal('0')
            normal_gains = Decimal('0')
            
            for transaction in transactions:
                gain = self._calculate_transaction_gain(transaction, user_id)
                if gain > 0:
                    # Verificar se é day trade (compra e venda no mesmo dia)
                    if self._is_day_trade(transaction, user_id):
                        day_trade_gains += gain
                    else:
                        normal_gains += gain
                    total_gains += gain
            
            # Calcular imposto devido
            tax_due = Decimal('0')
            if not is_exempt and total_gains > 0:
                # Day trade: 20%
                day_trade_tax = day_trade_gains * self.DAY_TRADE_TAX_RATE
                # Ganhos normais: 15%
                normal_tax = normal_gains * self.CAPITAL_GAINS_TAX_RATE
                tax_due = day_trade_tax + normal_tax
            
            # Verificar se precisa declarar IN 1888
            needs_in1888 = total_sales >= self.IN1888_THRESHOLD
            
            return {
                'success': True,
                'month': month,
                'year': year,
                'total_sales_brl': float(total_sales),
                'total_gains_brl': float(total_gains),
                'normal_gains_brl': float(normal_gains),
                'day_trade_gains_brl': float(day_trade_gains),
                'tax_due_brl': float(tax_due),
                'day_trade_tax_brl': float(day_trade_gains * self.DAY_TRADE_TAX_RATE if day_trade_gains > 0 else 0),
                'normal_tax_brl': float(normal_gains * self.CAPITAL_GAINS_TAX_RATE if normal_gains > 0 else 0),
                'is_exempt': is_exempt,
                'needs_in1888': needs_in1888,
                'transactions_count': len(transactions),
                'exemption_limit_brl': float(self.MONTHLY_EXEMPTION_LIMIT),
                'in1888_threshold_brl': float(self.IN1888_THRESHOLD)
            }
            
        except Exception as e:
            return {
                'success': False,
                'message': f'Erro ao calcular impostos: {str(e)}'
            }
    
    def calculate_annual_summary(self, user_id: int, year: int) -> Dict[str, Any]:
        """
        Calcula resumo anual para declaração de IR
        """
        try:
            monthly_results = []
            total_annual_sales = Decimal('0')
            total_annual_gains = Decimal('0')
            total_annual_tax = Decimal('0')
            months_with_sales = 0
            months_exempt = 0
            
            for month in range(1, 13):
                monthly_calc = self.calculate_monthly_tax(user_id, month, year)
                if monthly_calc['success']:
                    monthly_results.append(monthly_calc)
                    
                    sales = Decimal(str(monthly_calc['total_sales_brl']))
                    gains = Decimal(str(monthly_calc['total_gains_brl']))
                    tax = Decimal(str(monthly_calc['tax_due_brl']))
                    
                    total_annual_sales += sales
                    total_annual_gains += gains
                    total_annual_tax += tax
                    
                    if sales > 0:
                        months_with_sales += 1
                    if monthly_calc['is_exempt']:
                        months_exempt += 1
            
            # Calcular prejuízos acumulados (se houver)
            accumulated_losses = self._calculate_accumulated_losses(user_id, year)
            
            # Ganhos líquidos após compensação de prejuízos
            net_gains = max(Decimal('0'), total_annual_gains - accumulated_losses)
            
            return {
                'success': True,
                'year': year,
                'total_annual_sales_brl': float(total_annual_sales),
                'total_annual_gains_brl': float(total_annual_gains),
                'accumulated_losses_brl': float(accumulated_losses),
                'net_gains_brl': float(net_gains),
                'total_annual_tax_brl': float(total_annual_tax),
                'months_with_sales': months_with_sales,
                'months_exempt': months_exempt,
                'monthly_details': monthly_results,
                'average_monthly_sales': float(total_annual_sales / 12),
                'effective_tax_rate': float(total_annual_tax / total_annual_gains * 100) if total_annual_gains > 0 else 0
            }
            
        except Exception as e:
            return {
                'success': False,
                'message': f'Erro ao calcular resumo anual: {str(e)}'
            }
    
    def _calculate_transaction_gain(self, transaction: Transaction, user_id: int) -> Decimal:
        """
        Calcula ganho/perda de uma transação específica usando FIFO
        """
        try:
            if transaction.type not in ['sell', 'swap']:
                return Decimal('0')
            
            # Para simplificação, usar o valor total da transação
            # Em implementação completa, seria necessário calcular custo médio FIFO
            sale_value = Decimal(str(transaction.total_brl)) if transaction.total_brl else Decimal('0')
            
            # Estimar custo baseado no preço médio histórico (simplificado)
            # Em implementação real, seria necessário rastrear compras anteriores
            estimated_cost = sale_value * Decimal('0.8')  # Assumir 20% de ganho médio
            
            gain = sale_value - estimated_cost
            return max(Decimal('0'), gain)  # Não retornar prejuízos negativos aqui
            
        except Exception:
            return Decimal('0')
    
    def _is_day_trade(self, transaction: Transaction, user_id: int) -> bool:
        """
        Verifica se a transação é day trade (compra e venda no mesmo dia)
        """
        try:
            # Buscar compras do mesmo ativo no mesmo dia
            same_day_start = transaction.date.replace(hour=0, minute=0, second=0, microsecond=0)
            same_day_end = same_day_start + timedelta(days=1)
            
            buy_transactions = Transaction.query.filter(
                Transaction.user_id == user_id,
                Transaction.type == 'buy',
                Transaction.to_asset == transaction.from_asset,
                Transaction.date >= same_day_start,
                Transaction.date < same_day_end
            ).count()
            
            return buy_transactions > 0
            
        except Exception:
            return False
    
    def _calculate_accumulated_losses(self, user_id: int, year: int) -> Decimal:
        """
        Calcula prejuízos acumulados de anos anteriores
        """
        # Implementação simplificada - retorna zero
        # Em implementação completa, seria necessário rastrear prejuízos históricos
        return Decimal('0')
    
    def generate_darf_data(self, user_id: int, month: int, year: int) -> Dict[str, Any]:
        """
        Gera dados para preenchimento da DARF (Documento de Arrecadação de Receitas Federais)
        """
        try:
            tax_calc = self.calculate_monthly_tax(user_id, month, year)
            
            if not tax_calc['success'] or tax_calc['is_exempt']:
                return {
                    'success': False,
                    'message': 'Não há imposto devido para este período'
                }
            
            # Data de vencimento: último dia útil do mês seguinte
            due_date = self._get_darf_due_date(month, year)
            
            # Código da receita para ganhos de capital em criptomoedas
            revenue_code = '0132'  # Ganhos de Capital
            
            return {
                'success': True,
                'revenue_code': revenue_code,
                'reference_period': f'{month:02d}/{year}',
                'due_date': due_date.strftime('%d/%m/%Y'),
                'tax_amount': tax_calc['tax_due_brl'],
                'normal_gains_tax': tax_calc.get('normal_tax_brl', 0),
                'day_trade_tax': tax_calc.get('day_trade_tax_brl', 0),
                'total_gains': tax_calc['total_gains_brl'],
                'total_sales': tax_calc['total_sales_brl'],
                'instructions': [
                    'Preencher DARF com código de receita 0132',
                    'Período de apuração: mês/ano da venda',
                    'Vencimento: último dia útil do mês seguinte',
                    'Valor: conforme cálculo de ganhos de capital'
                ]
            }
            
        except Exception as e:
            return {
                'success': False,
                'message': f'Erro ao gerar dados da DARF: {str(e)}'
            }
    
    def _get_darf_due_date(self, month: int, year: int) -> datetime:
        """
        Calcula data de vencimento da DARF (último dia útil do mês seguinte)
        """
        if month == 12:
            next_month = 1
            next_year = year + 1
        else:
            next_month = month + 1
            next_year = year
        
        # Último dia do mês seguinte
        last_day = calendar.monthrange(next_year, next_month)[1]
        due_date = datetime(next_year, next_month, last_day)
        
        # Se cair em fim de semana, antecipar para sexta-feira
        while due_date.weekday() > 4:  # 5=sábado, 6=domingo
            due_date -= timedelta(days=1)
        
        return due_date
    
    def get_portfolio_summary(self, user_id: int) -> Dict[str, Any]:
        """
        Gera resumo do portfólio atual do usuário
        """
        try:
            # Buscar todas as transações do usuário
            transactions = Transaction.query.filter_by(user_id=user_id).order_by(Transaction.date).all()
            
            if not transactions:
                return {
                    'success': True,
                    'assets': {},
                    'total_invested_brl': 0,
                    'total_current_value_brl': 0,
                    'total_unrealized_gains_brl': 0
                }
            
            # Calcular saldos por ativo
            asset_balances = {}
            
            for transaction in transactions:
                # Processar entrada de ativo
                if transaction.to_asset and transaction.to_amount:
                    asset = transaction.to_asset
                    if asset not in asset_balances:
                        asset_balances[asset] = {
                            'balance': Decimal('0'),
                            'total_invested': Decimal('0'),
                            'avg_cost': Decimal('0')
                        }
                    
                    amount = Decimal(str(transaction.to_amount))
                    cost = Decimal(str(transaction.total_brl)) if transaction.total_brl else Decimal('0')
                    
                    # Atualizar custo médio
                    old_balance = asset_balances[asset]['balance']
                    old_invested = asset_balances[asset]['total_invested']
                    
                    asset_balances[asset]['balance'] += amount
                    asset_balances[asset]['total_invested'] += cost
                    
                    if asset_balances[asset]['balance'] > 0:
                        asset_balances[asset]['avg_cost'] = asset_balances[asset]['total_invested'] / asset_balances[asset]['balance']
                
                # Processar saída de ativo
                if transaction.from_asset and transaction.from_amount:
                    asset = transaction.from_asset
                    if asset in asset_balances:
                        amount = Decimal(str(transaction.from_amount))
                        
                        # Reduzir saldo proporcionalmente
                        if asset_balances[asset]['balance'] >= amount:
                            ratio = amount / asset_balances[asset]['balance']
                            asset_balances[asset]['balance'] -= amount
                            asset_balances[asset]['total_invested'] *= (1 - ratio)
                        else:
                            # Zerar se venda maior que saldo
                            asset_balances[asset]['balance'] = Decimal('0')
                            asset_balances[asset]['total_invested'] = Decimal('0')
            
            # Converter para formato de resposta
            portfolio = {}
            total_invested = Decimal('0')
            total_current_value = Decimal('0')
            
            for asset, data in asset_balances.items():
                if data['balance'] > 0:
                    # Buscar preço atual (simplificado - usar preço fixo)
                    current_price = self._get_current_price(asset)
                    current_value = data['balance'] * current_price
                    unrealized_gain = current_value - data['total_invested']
                    
                    portfolio[asset] = {
                        'balance': float(data['balance']),
                        'avg_cost_brl': float(data['avg_cost']),
                        'total_invested_brl': float(data['total_invested']),
                        'current_price_brl': float(current_price),
                        'current_value_brl': float(current_value),
                        'unrealized_gain_brl': float(unrealized_gain),
                        'unrealized_gain_percent': float(unrealized_gain / data['total_invested'] * 100) if data['total_invested'] > 0 else 0
                    }
                    
                    total_invested += data['total_invested']
                    total_current_value += current_value
            
            total_unrealized_gains = total_current_value - total_invested
            
            return {
                'success': True,
                'assets': portfolio,
                'total_invested_brl': float(total_invested),
                'total_current_value_brl': float(total_current_value),
                'total_unrealized_gains_brl': float(total_unrealized_gains),
                'total_unrealized_gains_percent': float(total_unrealized_gains / total_invested * 100) if total_invested > 0 else 0,
                'assets_count': len(portfolio)
            }
            
        except Exception as e:
            return {
                'success': False,
                'message': f'Erro ao calcular portfólio: {str(e)}'
            }
    
    def _get_current_price(self, asset_symbol: str) -> Decimal:
        """
        Busca preço atual do ativo (implementação simplificada)
        """
        # Preços fixos para demonstração
        prices = {
            'BTC': Decimal('350000'),
            'ETH': Decimal('18000'),
            'ADA': Decimal('2.5'),
            'SOL': Decimal('1000'),
            'USDT': Decimal('5'),
            'BNB': Decimal('1500'),
            'XRP': Decimal('3'),
            'DOGE': Decimal('0.5')
        }
        
        return prices.get(asset_symbol, Decimal('1'))

