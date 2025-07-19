from src.models.user import db
from datetime import datetime
from decimal import Decimal
import json

class CryptoAsset(db.Model):
    __tablename__ = 'crypto_assets'
    
    id = db.Column(db.Integer, primary_key=True)
    symbol = db.Column(db.String(20), nullable=False, unique=True)
    name = db.Column(db.String(100), nullable=False)
    current_price_brl = db.Column(db.Numeric(20, 10), default=0)
    current_price_usd = db.Column(db.Numeric(20, 10), default=0)
    price_change_24h = db.Column(db.Numeric(10, 4), default=0)
    market_cap = db.Column(db.Numeric(20, 2), default=0)
    volume_24h = db.Column(db.Numeric(20, 2), default=0)
    active = db.Column(db.Boolean, default=True)
    created_at = db.Column(db.DateTime, default=datetime.utcnow)
    updated_at = db.Column(db.DateTime, default=datetime.utcnow, onupdate=datetime.utcnow)
    
    def to_dict(self):
        return {
            'id': self.id,
            'symbol': self.symbol,
            'name': self.name,
            'current_price_brl': float(self.current_price_brl) if self.current_price_brl else 0,
            'current_price_usd': float(self.current_price_usd) if self.current_price_usd else 0,
            'price_change_24h': float(self.price_change_24h) if self.price_change_24h else 0,
            'market_cap': float(self.market_cap) if self.market_cap else 0,
            'volume_24h': float(self.volume_24h) if self.volume_24h else 0,
            'active': self.active,
            'created_at': self.created_at.isoformat() if self.created_at else None,
            'updated_at': self.updated_at.isoformat() if self.updated_at else None
        }

class Exchange(db.Model):
    __tablename__ = 'exchanges'
    
    id = db.Column(db.Integer, primary_key=True)
    name = db.Column(db.String(100), nullable=False)
    country = db.Column(db.String(2), nullable=False)  # ISO country code
    api_url = db.Column(db.String(255))
    active = db.Column(db.Boolean, default=True)
    created_at = db.Column(db.DateTime, default=datetime.utcnow)
    
    def to_dict(self):
        return {
            'id': self.id,
            'name': self.name,
            'country': self.country,
            'api_url': self.api_url,
            'active': self.active,
            'created_at': self.created_at.isoformat() if self.created_at else None
        }

class UserApiKey(db.Model):
    __tablename__ = 'user_api_keys'
    
    id = db.Column(db.Integer, primary_key=True)
    user_id = db.Column(db.Integer, db.ForeignKey('user.id'), nullable=False)
    exchange_id = db.Column(db.Integer, db.ForeignKey('exchanges.id'), nullable=False)
    api_key = db.Column(db.Text, nullable=False)  # Encrypted
    secret_key = db.Column(db.Text, nullable=False)  # Encrypted
    passphrase = db.Column(db.Text)  # Encrypted, optional
    active = db.Column(db.Boolean, default=True)
    created_at = db.Column(db.DateTime, default=datetime.utcnow)
    
    # Relationships
    exchange = db.relationship('Exchange', backref='api_keys')
    
    def to_dict(self):
        return {
            'id': self.id,
            'user_id': self.user_id,
            'exchange_id': self.exchange_id,
            'exchange_name': self.exchange.name if self.exchange else None,
            'active': self.active,
            'created_at': self.created_at.isoformat() if self.created_at else None
        }

class Transaction(db.Model):
    __tablename__ = 'transactions'
    
    id = db.Column(db.Integer, primary_key=True)
    user_id = db.Column(db.Integer, db.ForeignKey('user.id'), nullable=False)
    exchange_id = db.Column(db.Integer, db.ForeignKey('exchanges.id'))
    
    # Transaction details
    type = db.Column(db.String(20), nullable=False)  # buy, sell, transfer, etc.
    date = db.Column(db.DateTime, nullable=False)
    
    # From asset (what was sold/sent)
    from_asset = db.Column(db.String(20))
    from_amount = db.Column(db.Numeric(20, 10))
    
    # To asset (what was bought/received)
    to_asset = db.Column(db.String(20))
    to_amount = db.Column(db.Numeric(20, 10))
    
    # Pricing
    price = db.Column(db.Numeric(20, 10))  # Unit price
    total_brl = db.Column(db.Numeric(20, 2))  # Total value in BRL
    total_usd = db.Column(db.Numeric(20, 2))  # Total value in USD
    
    # Fees
    fee_amount = db.Column(db.Numeric(20, 10), default=0)
    fee_asset = db.Column(db.String(20))
    fee_brl = db.Column(db.Numeric(20, 2), default=0)
    
    # Additional info
    external_id = db.Column(db.String(100))  # Exchange transaction ID
    notes = db.Column(db.Text)
    
    created_at = db.Column(db.DateTime, default=datetime.utcnow)
    updated_at = db.Column(db.DateTime, default=datetime.utcnow, onupdate=datetime.utcnow)
    
    # Relationships
    exchange = db.relationship('Exchange', backref='transactions')
    
    def to_dict(self):
        return {
            'id': self.id,
            'user_id': self.user_id,
            'exchange_id': self.exchange_id,
            'exchange_name': self.exchange.name if self.exchange else None,
            'type': self.type,
            'date': self.date.isoformat() if self.date else None,
            'from_asset': self.from_asset,
            'from_amount': float(self.from_amount) if self.from_amount else None,
            'to_asset': self.to_asset,
            'to_amount': float(self.to_amount) if self.to_amount else None,
            'price': float(self.price) if self.price else None,
            'total_brl': float(self.total_brl) if self.total_brl else None,
            'total_usd': float(self.total_usd) if self.total_usd else None,
            'fee_amount': float(self.fee_amount) if self.fee_amount else 0,
            'fee_asset': self.fee_asset,
            'fee_brl': float(self.fee_brl) if self.fee_brl else 0,
            'external_id': self.external_id,
            'notes': self.notes,
            'created_at': self.created_at.isoformat() if self.created_at else None,
            'updated_at': self.updated_at.isoformat() if self.updated_at else None
        }

class TaxRule(db.Model):
    __tablename__ = 'tax_rules'
    
    id = db.Column(db.Integer, primary_key=True)
    user_id = db.Column(db.Integer, db.ForeignKey('user.id'), nullable=False)
    country = db.Column(db.String(2), nullable=False, default='BR')
    rule_type = db.Column(db.String(50), nullable=False)  # capital_gains, monthly_report, etc.
    threshold_amount = db.Column(db.Numeric(20, 2))  # R$ 35,000 for BR monthly report
    tax_rate = db.Column(db.Numeric(5, 4))  # 0.15 for 15%
    active = db.Column(db.Boolean, default=True)
    created_at = db.Column(db.DateTime, default=datetime.utcnow)
    
    def to_dict(self):
        return {
            'id': self.id,
            'user_id': self.user_id,
            'country': self.country,
            'rule_type': self.rule_type,
            'threshold_amount': float(self.threshold_amount) if self.threshold_amount else None,
            'tax_rate': float(self.tax_rate) if self.tax_rate else None,
            'active': self.active,
            'created_at': self.created_at.isoformat() if self.created_at else None
        }

class IN1888Report(db.Model):
    __tablename__ = 'in1888_reports'
    
    id = db.Column(db.Integer, primary_key=True)
    user_id = db.Column(db.Integer, db.ForeignKey('user.id'), nullable=False)
    month = db.Column(db.Integer, nullable=False)  # 1-12
    year = db.Column(db.Integer, nullable=False)
    total_operations_brl = db.Column(db.Numeric(20, 2), nullable=False)
    file_content = db.Column(db.Text)  # Generated file content
    file_hash = db.Column(db.String(64))  # SHA256 hash for integrity
    submitted = db.Column(db.Boolean, default=False)
    submitted_at = db.Column(db.DateTime)
    created_at = db.Column(db.DateTime, default=datetime.utcnow)
    
    def to_dict(self):
        return {
            'id': self.id,
            'user_id': self.user_id,
            'month': self.month,
            'year': self.year,
            'total_operations_brl': float(self.total_operations_brl),
            'submitted': self.submitted,
            'submitted_at': self.submitted_at.isoformat() if self.submitted_at else None,
            'created_at': self.created_at.isoformat() if self.created_at else None
        }

class Wallet(db.Model):
    __tablename__ = 'wallets'
    
    id = db.Column(db.Integer, primary_key=True)
    user_id = db.Column(db.Integer, db.ForeignKey('user.id'), nullable=False)
    name = db.Column(db.String(100), nullable=False)
    address = db.Column(db.String(255))
    wallet_type = db.Column(db.String(50), nullable=False)  # exchange, hardware, software
    exchange_id = db.Column(db.Integer, db.ForeignKey('exchanges.id'))
    active = db.Column(db.Boolean, default=True)
    created_at = db.Column(db.DateTime, default=datetime.utcnow)
    
    # Relationships
    exchange = db.relationship('Exchange', backref='wallets')
    
    def to_dict(self):
        return {
            'id': self.id,
            'user_id': self.user_id,
            'name': self.name,
            'address': self.address,
            'wallet_type': self.wallet_type,
            'exchange_id': self.exchange_id,
            'exchange_name': self.exchange.name if self.exchange else None,
            'active': self.active,
            'created_at': self.created_at.isoformat() if self.created_at else None
        }

class WalletBalance(db.Model):
    __tablename__ = 'wallet_balances'
    
    id = db.Column(db.Integer, primary_key=True)
    wallet_id = db.Column(db.Integer, db.ForeignKey('wallets.id'), nullable=False)
    asset_symbol = db.Column(db.String(20), nullable=False)
    balance = db.Column(db.Numeric(20, 10), nullable=False, default=0)
    balance_brl = db.Column(db.Numeric(20, 2), default=0)
    last_updated = db.Column(db.DateTime, default=datetime.utcnow)
    
    # Relationships
    wallet = db.relationship('Wallet', backref='balances')
    
    def to_dict(self):
        return {
            'id': self.id,
            'wallet_id': self.wallet_id,
            'wallet_name': self.wallet.name if self.wallet else None,
            'asset_symbol': self.asset_symbol,
            'balance': float(self.balance),
            'balance_brl': float(self.balance_brl) if self.balance_brl else 0,
            'last_updated': self.last_updated.isoformat() if self.last_updated else None
        }

