import pandas as pd
import numpy as np
from datetime import datetime, timedelta
from decimal import Decimal
from typing import Dict, List, Optional, Tuple
from dataclasses import dataclass
from enum import Enum

class StrategyType(Enum):
    BUY_AND_HOLD = "buy_and_hold"
    DCA = "dca"  # Dollar Cost Averaging
    RSI = "rsi"
    MOVING_AVERAGE = "moving_average"
    BOLLINGER_BANDS = "bollinger_bands"
    MACD = "macd"

@dataclass
class BacktestResult:
    strategy_name: str
    asset: str
    start_date: datetime
    end_date: datetime
    initial_investment: Decimal
    final_value: Decimal
    total_return: Decimal
    total_return_percent: Decimal
    max_drawdown: Decimal
    sharpe_ratio: float
    number_of_trades: int
    win_rate: float
    avg_trade_return: Decimal
    benchmark_return: Decimal
    alpha: Decimal  # Retorno acima do benchmark
    trades: List[Dict]

class BacktestingService:
    """Serviço para backtesting de estratégias de trading"""
    
    def __init__(self):
        self.strategies = {
            StrategyType.BUY_AND_HOLD: self._buy_and_hold_strategy,
            StrategyType.DCA: self._dca_strategy,
            StrategyType.RSI: self._rsi_strategy,
            StrategyType.MOVING_AVERAGE: self._moving_average_strategy,
            StrategyType.BOLLINGER_BANDS: self._bollinger_bands_strategy,
            StrategyType.MACD: self._macd_strategy
        }
    
    def run_backtest(
        self,
        strategy_type: StrategyType,
        asset: str,
        start_date: datetime,
        end_date: datetime,
        initial_investment: Decimal,
        strategy_params: Dict = None
    ) -> BacktestResult:
        """Executa um backtest para uma estratégia específica"""
        
        # Gera dados históricos simulados (em produção, viria de uma API)
        price_data = self._generate_historical_data(asset, start_date, end_date)
        
        # Executa a estratégia
        strategy_func = self.strategies[strategy_type]
        trades, portfolio_values = strategy_func(
            price_data, 
            initial_investment, 
            strategy_params or {}
        )
        
        # Calcula métricas de performance
        result = self._calculate_metrics(
            strategy_type.value,
            asset,
            start_date,
            end_date,
            initial_investment,
            trades,
            portfolio_values,
            price_data
        )
        
        return result
    
    def compare_strategies(
        self,
        strategies: List[Tuple[StrategyType, Dict]],
        asset: str,
        start_date: datetime,
        end_date: datetime,
        initial_investment: Decimal
    ) -> List[BacktestResult]:
        """Compara múltiplas estratégias no mesmo período"""
        
        results = []
        for strategy_type, params in strategies:
            result = self.run_backtest(
                strategy_type,
                asset,
                start_date,
                end_date,
                initial_investment,
                params
            )
            results.append(result)
        
        return sorted(results, key=lambda x: x.total_return_percent, reverse=True)
    
    def _generate_historical_data(
        self, 
        asset: str, 
        start_date: datetime, 
        end_date: datetime
    ) -> pd.DataFrame:
        """Gera dados históricos simulados para backtesting"""
        
        # Preços base para diferentes ativos
        base_prices = {
            'BTC': 150000,
            'ETH': 8000,
            'ADA': 1.5,
            'DOT': 25,
            'LINK': 50,
            'UNI': 20,
            'MATIC': 2.5,
            'SOL': 200
        }
        
        base_price = base_prices.get(asset, 100)
        
        # Gera série temporal diária
        dates = pd.date_range(start=start_date, end=end_date, freq='D')
        
        # Simula movimento de preços com tendência e volatilidade
        np.random.seed(42)  # Para resultados reproduzíveis
        
        returns = np.random.normal(0.001, 0.03, len(dates))  # Retorno médio 0.1% com volatilidade 3%
        
        # Adiciona tendência de alta gradual
        trend = np.linspace(0, 0.5, len(dates))  # 50% de alta no período
        returns += trend / len(dates)
        
        # Calcula preços
        prices = [base_price]
        for ret in returns[1:]:
            new_price = prices[-1] * (1 + ret)
            prices.append(max(new_price, base_price * 0.1))  # Não deixa cair mais que 90%
        
        # Cria DataFrame
        df = pd.DataFrame({
            'date': dates,
            'open': prices,
            'high': [p * (1 + np.random.uniform(0, 0.02)) for p in prices],
            'low': [p * (1 - np.random.uniform(0, 0.02)) for p in prices],
            'close': prices,
            'volume': np.random.uniform(1000000, 10000000, len(dates))
        })
        
        # Adiciona indicadores técnicos
        df = self._add_technical_indicators(df)
        
        return df
    
    def _add_technical_indicators(self, df: pd.DataFrame) -> pd.DataFrame:
        """Adiciona indicadores técnicos aos dados"""
        
        # RSI
        df['rsi'] = self._calculate_rsi(df['close'])
        
        # Médias móveis
        df['sma_20'] = df['close'].rolling(window=20).mean()
        df['sma_50'] = df['close'].rolling(window=50).mean()
        df['ema_12'] = df['close'].ewm(span=12).mean()
        df['ema_26'] = df['close'].ewm(span=26).mean()
        
        # MACD
        df['macd'] = df['ema_12'] - df['ema_26']
        df['macd_signal'] = df['macd'].ewm(span=9).mean()
        
        # Bollinger Bands
        df['bb_middle'] = df['close'].rolling(window=20).mean()
        bb_std = df['close'].rolling(window=20).std()
        df['bb_upper'] = df['bb_middle'] + (bb_std * 2)
        df['bb_lower'] = df['bb_middle'] - (bb_std * 2)
        
        return df
    
    def _calculate_rsi(self, prices: pd.Series, period: int = 14) -> pd.Series:
        """Calcula o RSI (Relative Strength Index)"""
        delta = prices.diff()
        gain = (delta.where(delta > 0, 0)).rolling(window=period).mean()
        loss = (-delta.where(delta < 0, 0)).rolling(window=period).mean()
        rs = gain / loss
        rsi = 100 - (100 / (1 + rs))
        return rsi
    
    def _buy_and_hold_strategy(
        self, 
        data: pd.DataFrame, 
        initial_investment: Decimal, 
        params: Dict
    ) -> Tuple[List[Dict], List[Decimal]]:
        """Estratégia Buy and Hold"""
        
        trades = []
        portfolio_values = []
        
        # Compra no primeiro dia
        first_price = Decimal(str(data.iloc[0]['close']))
        shares = initial_investment / first_price
        
        trades.append({
            'date': data.iloc[0]['date'],
            'type': 'buy',
            'price': first_price,
            'shares': shares,
            'value': initial_investment
        })
        
        # Calcula valor do portfólio ao longo do tempo
        for _, row in data.iterrows():
            current_price = Decimal(str(row['close']))
            portfolio_value = shares * current_price
            portfolio_values.append(portfolio_value)
        
        return trades, portfolio_values
    
    def _dca_strategy(
        self, 
        data: pd.DataFrame, 
        initial_investment: Decimal, 
        params: Dict
    ) -> Tuple[List[Dict], List[Decimal]]:
        """Estratégia Dollar Cost Averaging"""
        
        frequency = params.get('frequency', 30)  # Investir a cada 30 dias
        investment_amount = params.get('amount', initial_investment / 12)  # Dividir em 12 partes
        
        trades = []
        portfolio_values = []
        total_shares = Decimal('0')
        remaining_cash = initial_investment
        last_investment_day = 0
        
        for i, row in data.iterrows():
            current_price = Decimal(str(row['close']))
            
            # Verifica se é dia de investir
            if i - last_investment_day >= frequency and remaining_cash >= investment_amount:
                shares_bought = investment_amount / current_price
                total_shares += shares_bought
                remaining_cash -= investment_amount
                last_investment_day = i
                
                trades.append({
                    'date': row['date'],
                    'type': 'buy',
                    'price': current_price,
                    'shares': shares_bought,
                    'value': investment_amount
                })
            
            # Calcula valor do portfólio
            portfolio_value = (total_shares * current_price) + remaining_cash
            portfolio_values.append(portfolio_value)
        
        return trades, portfolio_values
    
    def _rsi_strategy(
        self, 
        data: pd.DataFrame, 
        initial_investment: Decimal, 
        params: Dict
    ) -> Tuple[List[Dict], List[Decimal]]:
        """Estratégia baseada em RSI"""
        
        oversold_threshold = params.get('oversold', 30)
        overbought_threshold = params.get('overbought', 70)
        
        trades = []
        portfolio_values = []
        shares = Decimal('0')
        cash = initial_investment
        
        for _, row in data.iterrows():
            current_price = Decimal(str(row['close']))
            rsi = row['rsi']
            
            if pd.notna(rsi):
                # Compra quando RSI < 30 (oversold)
                if rsi < oversold_threshold and cash > 0:
                    shares_to_buy = cash / current_price
                    shares += shares_to_buy
                    
                    trades.append({
                        'date': row['date'],
                        'type': 'buy',
                        'price': current_price,
                        'shares': shares_to_buy,
                        'value': cash
                    })
                    
                    cash = Decimal('0')
                
                # Vende quando RSI > 70 (overbought)
                elif rsi > overbought_threshold and shares > 0:
                    cash = shares * current_price
                    
                    trades.append({
                        'date': row['date'],
                        'type': 'sell',
                        'price': current_price,
                        'shares': shares,
                        'value': cash
                    })
                    
                    shares = Decimal('0')
            
            # Calcula valor do portfólio
            portfolio_value = (shares * current_price) + cash
            portfolio_values.append(portfolio_value)
        
        return trades, portfolio_values
    
    def _moving_average_strategy(
        self, 
        data: pd.DataFrame, 
        initial_investment: Decimal, 
        params: Dict
    ) -> Tuple[List[Dict], List[Decimal]]:
        """Estratégia de cruzamento de médias móveis"""
        
        trades = []
        portfolio_values = []
        shares = Decimal('0')
        cash = initial_investment
        position = None  # 'long' ou None
        
        for i, row in data.iterrows():
            if i == 0:
                portfolio_values.append(initial_investment)
                continue
                
            current_price = Decimal(str(row['close']))
            sma_20 = row['sma_20']
            sma_50 = row['sma_50']
            prev_sma_20 = data.iloc[i-1]['sma_20']
            prev_sma_50 = data.iloc[i-1]['sma_50']
            
            if pd.notna(sma_20) and pd.notna(sma_50):
                # Sinal de compra: SMA 20 cruza acima da SMA 50
                if (prev_sma_20 <= prev_sma_50 and sma_20 > sma_50 and 
                    position != 'long' and cash > 0):
                    
                    shares = cash / current_price
                    position = 'long'
                    
                    trades.append({
                        'date': row['date'],
                        'type': 'buy',
                        'price': current_price,
                        'shares': shares,
                        'value': cash
                    })
                    
                    cash = Decimal('0')
                
                # Sinal de venda: SMA 20 cruza abaixo da SMA 50
                elif (prev_sma_20 >= prev_sma_50 and sma_20 < sma_50 and 
                      position == 'long' and shares > 0):
                    
                    cash = shares * current_price
                    position = None
                    
                    trades.append({
                        'date': row['date'],
                        'type': 'sell',
                        'price': current_price,
                        'shares': shares,
                        'value': cash
                    })
                    
                    shares = Decimal('0')
            
            # Calcula valor do portfólio
            portfolio_value = (shares * current_price) + cash
            portfolio_values.append(portfolio_value)
        
        return trades, portfolio_values
    
    def _bollinger_bands_strategy(
        self, 
        data: pd.DataFrame, 
        initial_investment: Decimal, 
        params: Dict
    ) -> Tuple[List[Dict], List[Decimal]]:
        """Estratégia baseada em Bollinger Bands"""
        
        trades = []
        portfolio_values = []
        shares = Decimal('0')
        cash = initial_investment
        
        for _, row in data.iterrows():
            current_price = Decimal(str(row['close']))
            bb_upper = row['bb_upper']
            bb_lower = row['bb_lower']
            
            if pd.notna(bb_upper) and pd.notna(bb_lower):
                # Compra quando preço toca a banda inferior
                if current_price <= Decimal(str(bb_lower)) and cash > 0:
                    shares_to_buy = cash / current_price
                    shares += shares_to_buy
                    
                    trades.append({
                        'date': row['date'],
                        'type': 'buy',
                        'price': current_price,
                        'shares': shares_to_buy,
                        'value': cash
                    })
                    
                    cash = Decimal('0')
                
                # Vende quando preço toca a banda superior
                elif current_price >= Decimal(str(bb_upper)) and shares > 0:
                    cash = shares * current_price
                    
                    trades.append({
                        'date': row['date'],
                        'type': 'sell',
                        'price': current_price,
                        'shares': shares,
                        'value': cash
                    })
                    
                    shares = Decimal('0')
            
            # Calcula valor do portfólio
            portfolio_value = (shares * current_price) + cash
            portfolio_values.append(portfolio_value)
        
        return trades, portfolio_values
    
    def _macd_strategy(
        self, 
        data: pd.DataFrame, 
        initial_investment: Decimal, 
        params: Dict
    ) -> Tuple[List[Dict], List[Decimal]]:
        """Estratégia baseada em MACD"""
        
        trades = []
        portfolio_values = []
        shares = Decimal('0')
        cash = initial_investment
        position = None
        
        for i, row in data.iterrows():
            if i == 0:
                portfolio_values.append(initial_investment)
                continue
                
            current_price = Decimal(str(row['close']))
            macd = row['macd']
            macd_signal = row['macd_signal']
            prev_macd = data.iloc[i-1]['macd']
            prev_signal = data.iloc[i-1]['macd_signal']
            
            if pd.notna(macd) and pd.notna(macd_signal):
                # Sinal de compra: MACD cruza acima da linha de sinal
                if (prev_macd <= prev_signal and macd > macd_signal and 
                    position != 'long' and cash > 0):
                    
                    shares = cash / current_price
                    position = 'long'
                    
                    trades.append({
                        'date': row['date'],
                        'type': 'buy',
                        'price': current_price,
                        'shares': shares,
                        'value': cash
                    })
                    
                    cash = Decimal('0')
                
                # Sinal de venda: MACD cruza abaixo da linha de sinal
                elif (prev_macd >= prev_signal and macd < macd_signal and 
                      position == 'long' and shares > 0):
                    
                    cash = shares * current_price
                    position = None
                    
                    trades.append({
                        'date': row['date'],
                        'type': 'sell',
                        'price': current_price,
                        'shares': shares,
                        'value': cash
                    })
                    
                    shares = Decimal('0')
            
            # Calcula valor do portfólio
            portfolio_value = (shares * current_price) + cash
            portfolio_values.append(portfolio_value)
        
        return trades, portfolio_values
    
    def _calculate_metrics(
        self,
        strategy_name: str,
        asset: str,
        start_date: datetime,
        end_date: datetime,
        initial_investment: Decimal,
        trades: List[Dict],
        portfolio_values: List[Decimal],
        price_data: pd.DataFrame
    ) -> BacktestResult:
        """Calcula métricas de performance do backtest"""
        
        final_value = portfolio_values[-1] if portfolio_values else initial_investment
        total_return = final_value - initial_investment
        total_return_percent = (total_return / initial_investment) * 100
        
        # Calcula benchmark (buy and hold)
        first_price = Decimal(str(price_data.iloc[0]['close']))
        last_price = Decimal(str(price_data.iloc[-1]['close']))
        benchmark_return = ((last_price - first_price) / first_price) * 100
        
        # Alpha (retorno acima do benchmark)
        alpha = total_return_percent - benchmark_return
        
        # Max drawdown
        peak = initial_investment
        max_drawdown = Decimal('0')
        for value in portfolio_values:
            if value > peak:
                peak = value
            drawdown = ((peak - value) / peak) * 100
            if drawdown > max_drawdown:
                max_drawdown = drawdown
        
        # Métricas de trades
        buy_trades = [t for t in trades if t['type'] == 'buy']
        sell_trades = [t for t in trades if t['type'] == 'sell']
        
        winning_trades = 0
        total_trade_return = Decimal('0')
        
        # Calcula retorno por par de trades (compra/venda)
        for i, sell_trade in enumerate(sell_trades):
            if i < len(buy_trades):
                buy_trade = buy_trades[i]
                trade_return = ((sell_trade['price'] - buy_trade['price']) / buy_trade['price']) * 100
                total_trade_return += trade_return
                if trade_return > 0:
                    winning_trades += 1
        
        number_of_trades = len(sell_trades)
        win_rate = (winning_trades / number_of_trades * 100) if number_of_trades > 0 else 0
        avg_trade_return = total_trade_return / number_of_trades if number_of_trades > 0 else Decimal('0')
        
        # Sharpe ratio simplificado (assumindo taxa livre de risco = 0)
        if len(portfolio_values) > 1:
            returns = [float((portfolio_values[i] - portfolio_values[i-1]) / portfolio_values[i-1]) 
                      for i in range(1, len(portfolio_values))]
            sharpe_ratio = np.mean(returns) / np.std(returns) * np.sqrt(252) if np.std(returns) > 0 else 0
        else:
            sharpe_ratio = 0
        
        return BacktestResult(
            strategy_name=strategy_name,
            asset=asset,
            start_date=start_date,
            end_date=end_date,
            initial_investment=initial_investment,
            final_value=final_value,
            total_return=total_return,
            total_return_percent=total_return_percent,
            max_drawdown=max_drawdown,
            sharpe_ratio=sharpe_ratio,
            number_of_trades=number_of_trades,
            win_rate=win_rate,
            avg_trade_return=avg_trade_return,
            benchmark_return=benchmark_return,
            alpha=alpha,
            trades=trades
        )

