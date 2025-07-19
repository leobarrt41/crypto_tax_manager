import os
from dotenv import load_dotenv

# Carregar variáveis de ambiente
load_dotenv()

class Config:
    # Flask
    FLASK_PORT = int(os.getenv('FLASK_PORT', 5000))
    FLASK_DEBUG = os.getenv('FLASK_DEBUG', 'True').lower() == 'true'
    
    # APIs
    BINANCE_API_KEY = os.getenv('BINANCE_API_KEY')
    BINANCE_SECRET_KEY = os.getenv('BINANCE_SECRET_KEY')
    BINANCE_SANDBOX = os.getenv('BINANCE_SANDBOX', 'true').lower() == 'true'
    
    COINBASE_API_KEY = os.getenv('COINBASE_API_KEY')
    COINBASE_SECRET_KEY = os.getenv('COINBASE_SECRET_KEY')
    COINBASE_PASSPHRASE = os.getenv('COINBASE_PASSPHRASE')
    
    # Backtesting
    BACKTESTING_API_KEY = os.getenv('BACKTESTING_API_KEY', 'crypto_tax_backtesting_2024')
    
    # Database (se usar)
    DATABASE_URL = os.getenv('DATABASE_URL')