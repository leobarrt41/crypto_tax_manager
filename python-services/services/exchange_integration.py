import requests
import json
import hashlib
import hmac
import time
from datetime import datetime, timedelta
from decimal import Decimal
from typing import List, Dict, Optional
from src.models.crypto_models import Transaction, CryptoAsset, Exchange, UserApiKey
from src.models.user import db

class ExchangeIntegration:
    """Serviço para integração com APIs de exchanges de criptomoedas"""
    
    def __init__(self):
        self.supported_exchanges = {
            'binance': BinanceIntegration,
            'coinbase': CoinbaseIntegration,
            'mercadobitcoin': MercadoBitcoinIntegration,
            'kraken': KrakenIntegration
        }
    
    def get_user_api_keys(self, user_id: int) -> List[UserApiKey]:
        """Busca todas as chaves de API do usuário"""
        return UserApiKey.query.filter_by(user_id=user_id, is_active=True).all()
    
    def sync_transactions(self, user_id: int, exchange_name: str = None) -> Dict:
        """Sincroniza transações de uma ou todas as exchanges do usuário"""
        try:
            api_keys = self.get_user_api_keys(user_id)
            
            if exchange_name:
                api_keys = [key for key in api_keys if key.exchange.name.lower() == exchange_name.lower()]
            
            if not api_keys:
                return {
                    'success': False,
                    'message': 'Nenhuma chave de API encontrada'
                }
            
            total_imported = 0
            results = []
            
            for api_key in api_keys:
                exchange_name = api_key.exchange.name.lower()
                
                if exchange_name not in self.supported_exchanges:
                    results.append({
                        'exchange': exchange_name,
                        'success': False,
                        'message': 'Exchange não suportada'
                    })
                    continue
                
                integration = self.supported_exchanges[exchange_name](api_key)
                result = integration.import_transactions(user_id)
                
                if result['success']:
                    total_imported += result['imported_count']
                
                results.append({
                    'exchange': exchange_name,
                    'success': result['success'],
                    'imported_count': result.get('imported_count', 0),
                    'message': result.get('message', '')
                })
            
            return {
                'success': True,
                'total_imported': total_imported,
                'results': results
            }
            
        except Exception as e:
            return {
                'success': False,
                'message': f'Erro na sincronização: {str(e)}'
            }

class BaseExchangeIntegration:
    """Classe base para integrações com exchanges"""
    
    def __init__(self, api_key: UserApiKey):
        self.api_key = api_key
        self.exchange = api_key.exchange
        self.api_key_value = api_key.api_key
        self.api_secret = api_key.api_secret
        self.base_url = api_key.exchange.api_url
    
    def get_headers(self) -> Dict:
        """Headers básicos para requisições"""
        return {
            'Content-Type': 'application/json',
            'User-Agent': 'CryptoTaxManager/1.0'
        }
    
    def import_transactions(self, user_id: int) -> Dict:
        """Método que deve ser implementado por cada exchange"""
        raise NotImplementedError("Cada exchange deve implementar este método")
    
    def save_transaction(self, user_id: int, transaction_data: Dict) -> bool:
        """Salva uma transação no banco de dados"""
        try:
            # Verifica se a transação já existe
            existing = Transaction.query.filter_by(
                user_id=user_id,
                exchange_transaction_id=transaction_data.get('exchange_transaction_id')
            ).first()
            
            if existing:
                return False  # Transação já existe
            
            transaction = Transaction(
                user_id=user_id,
                exchange_id=self.exchange.id,
                exchange_transaction_id=transaction_data.get('exchange_transaction_id'),
                type=transaction_data.get('type'),
                from_asset=transaction_data.get('from_asset'),
                to_asset=transaction_data.get('to_asset'),
                from_amount=Decimal(str(transaction_data.get('from_amount', 0))),
                to_amount=Decimal(str(transaction_data.get('to_amount', 0))),
                fee_amount=Decimal(str(transaction_data.get('fee_amount', 0))),
                fee_asset=transaction_data.get('fee_asset'),
                price_brl=Decimal(str(transaction_data.get('price_brl', 0))),
                total_brl=Decimal(str(transaction_data.get('total_brl', 0))),
                fee_brl=Decimal(str(transaction_data.get('fee_brl', 0))),
                date=transaction_data.get('date'),
                notes=transaction_data.get('notes', '')
            )
            
            db.session.add(transaction)
            db.session.commit()
            return True
            
        except Exception as e:
            db.session.rollback()
            print(f"Erro ao salvar transação: {e}")
            return False

class BinanceIntegration(BaseExchangeIntegration):
    """Integração com a API da Binance"""
    
    def __init__(self, api_key: UserApiKey):
        super().__init__(api_key)
        self.base_url = "https://api.binance.com"
    
    def get_signed_headers(self, params: str) -> Dict:
        """Gera headers assinados para a API da Binance"""
        timestamp = int(time.time() * 1000)
        query_string = f"{params}&timestamp={timestamp}"
        signature = hmac.new(
            self.api_secret.encode('utf-8'),
            query_string.encode('utf-8'),
            hashlib.sha256
        ).hexdigest()
        
        headers = self.get_headers()
        headers['X-MBX-APIKEY'] = self.api_key_value
        
        return headers, f"{query_string}&signature={signature}"
    
    def import_transactions(self, user_id: int) -> Dict:
        """Importa transações da Binance"""
        try:
            # Simula importação de transações da Binance
            # Em produção, faria chamadas reais para a API
            sample_transactions = [
                {
                    'exchange_transaction_id': 'binance_001',
                    'type': 'buy',
                    'from_asset': 'BRL',
                    'to_asset': 'BTC',
                    'from_amount': 50000,
                    'to_amount': 1.5,
                    'fee_amount': 0.001,
                    'fee_asset': 'BTC',
                    'price_brl': 33333.33,
                    'total_brl': 50000,
                    'fee_brl': 33.33,
                    'date': datetime.now() - timedelta(days=5),
                    'notes': 'Importado da Binance'
                },
                {
                    'exchange_transaction_id': 'binance_002',
                    'type': 'buy',
                    'from_asset': 'BRL',
                    'to_asset': 'ETH',
                    'from_amount': 20000,
                    'to_amount': 8.5,
                    'fee_amount': 0.01,
                    'fee_asset': 'ETH',
                    'price_brl': 2352.94,
                    'total_brl': 20000,
                    'fee_brl': 23.53,
                    'date': datetime.now() - timedelta(days=3),
                    'notes': 'Importado da Binance'
                }
            ]
            
            imported_count = 0
            for transaction_data in sample_transactions:
                if self.save_transaction(user_id, transaction_data):
                    imported_count += 1
            
            return {
                'success': True,
                'imported_count': imported_count,
                'message': f'{imported_count} transações importadas da Binance'
            }
            
        except Exception as e:
            return {
                'success': False,
                'message': f'Erro na importação da Binance: {str(e)}'
            }

class CoinbaseIntegration(BaseExchangeIntegration):
    """Integração com a API da Coinbase"""
    
    def import_transactions(self, user_id: int) -> Dict:
        """Importa transações da Coinbase"""
        try:
            # Simula importação de transações da Coinbase
            sample_transactions = [
                {
                    'exchange_transaction_id': 'coinbase_001',
                    'type': 'sell',
                    'from_asset': 'BTC',
                    'to_asset': 'USD',
                    'from_amount': 0.5,
                    'to_amount': 16500,
                    'fee_amount': 50,
                    'fee_asset': 'USD',
                    'price_brl': 165000,
                    'total_brl': 82500,
                    'fee_brl': 250,
                    'date': datetime.now() - timedelta(days=2),
                    'notes': 'Importado da Coinbase'
                }
            ]
            
            imported_count = 0
            for transaction_data in sample_transactions:
                if self.save_transaction(user_id, transaction_data):
                    imported_count += 1
            
            return {
                'success': True,
                'imported_count': imported_count,
                'message': f'{imported_count} transações importadas da Coinbase'
            }
            
        except Exception as e:
            return {
                'success': False,
                'message': f'Erro na importação da Coinbase: {str(e)}'
            }

class MercadoBitcoinIntegration(BaseExchangeIntegration):
    """Integração com a API do Mercado Bitcoin"""
    
    def import_transactions(self, user_id: int) -> Dict:
        """Importa transações do Mercado Bitcoin"""
        try:
            # Simula importação de transações do Mercado Bitcoin
            sample_transactions = [
                {
                    'exchange_transaction_id': 'mb_001',
                    'type': 'buy',
                    'from_asset': 'BRL',
                    'to_asset': 'BTC',
                    'from_amount': 25000,
                    'to_amount': 0.75,
                    'fee_amount': 125,
                    'fee_asset': 'BRL',
                    'price_brl': 33333.33,
                    'total_brl': 25000,
                    'fee_brl': 125,
                    'date': datetime.now() - timedelta(days=7),
                    'notes': 'Importado do Mercado Bitcoin'
                }
            ]
            
            imported_count = 0
            for transaction_data in sample_transactions:
                if self.save_transaction(user_id, transaction_data):
                    imported_count += 1
            
            return {
                'success': True,
                'imported_count': imported_count,
                'message': f'{imported_count} transações importadas do Mercado Bitcoin'
            }
            
        except Exception as e:
            return {
                'success': False,
                'message': f'Erro na importação do Mercado Bitcoin: {str(e)}'
            }

class KrakenIntegration(BaseExchangeIntegration):
    """Integração com a API da Kraken"""
    
    def import_transactions(self, user_id: int) -> Dict:
        """Importa transações da Kraken"""
        try:
            # Simula importação de transações da Kraken
            sample_transactions = [
                {
                    'exchange_transaction_id': 'kraken_001',
                    'type': 'swap',
                    'from_asset': 'BTC',
                    'to_asset': 'ETH',
                    'from_amount': 0.3,
                    'to_amount': 4.2,
                    'fee_amount': 0.001,
                    'fee_asset': 'BTC',
                    'price_brl': 0,  # Swap não tem preço direto em BRL
                    'total_brl': 0,
                    'fee_brl': 33.33,
                    'date': datetime.now() - timedelta(days=1),
                    'notes': 'Swap BTC/ETH importado da Kraken'
                }
            ]
            
            imported_count = 0
            for transaction_data in sample_transactions:
                if self.save_transaction(user_id, transaction_data):
                    imported_count += 1
            
            return {
                'success': True,
                'imported_count': imported_count,
                'message': f'{imported_count} transações importadas da Kraken'
            }
            
        except Exception as e:
            return {
                'success': False,
                'message': f'Erro na importação da Kraken: {str(e)}'
            }

# Serviço para buscar preços atuais de criptomoedas
class PriceService:
    """Serviço para buscar preços atuais de criptomoedas"""
    
    @staticmethod
    def get_current_prices(assets: List[str]) -> Dict[str, Decimal]:
        """Busca preços atuais das criptomoedas em BRL"""
        try:
            # Simula busca de preços (em produção usaria APIs como CoinGecko)
            mock_prices = {
                'BTC': Decimal('330000.00'),
                'ETH': Decimal('12500.00'),
                'ADA': Decimal('2.50'),
                'DOT': Decimal('45.00'),
                'LINK': Decimal('85.00'),
                'UNI': Decimal('35.00'),
                'MATIC': Decimal('4.20'),
                'SOL': Decimal('450.00')
            }
            
            return {asset: mock_prices.get(asset, Decimal('0')) for asset in assets}
            
        except Exception as e:
            print(f"Erro ao buscar preços: {e}")
            return {asset: Decimal('0') for asset in assets}
    
    @staticmethod
    def get_historical_price(asset: str, date: datetime) -> Decimal:
        """Busca preço histórico de uma criptomoeda"""
        try:
            # Simula busca de preço histórico
            base_prices = {
                'BTC': Decimal('320000.00'),
                'ETH': Decimal('12000.00'),
                'ADA': Decimal('2.30'),
                'DOT': Decimal('42.00'),
                'LINK': Decimal('80.00'),
                'UNI': Decimal('32.00'),
                'MATIC': Decimal('4.00'),
                'SOL': Decimal('420.00')
            }
            
            # Adiciona variação baseada na data
            days_ago = (datetime.now() - date).days
            variation = 1 + (days_ago * 0.01)  # 1% de variação por dia
            
            base_price = base_prices.get(asset, Decimal('0'))
            return base_price * Decimal(str(variation))
            
        except Exception as e:
            print(f"Erro ao buscar preço histórico: {e}")
            return Decimal('0')

