from datetime import datetime, timedelta
from decimal import Decimal
import hashlib
from typing import List, Dict, Any
from src.models.crypto_models import Transaction, IN1888Report, db

class IN1888Service:
    """
    Serviço para gerar arquivos de movimentação mensal conforme IN 1888/2019 da Receita Federal
    """
    
    # Códigos de operação conforme IN 1888
    OPERATION_CODES = {
        'buy': 'I',           # Compra e venda
        'sell': 'I',          # Compra e venda
        'swap': 'II',         # Permuta
        'gift': 'III',        # Doação
        'deposit': 'IV',      # Transferência para Exchange
        'withdrawal': 'V',    # Retirada da Exchange
        'lending': 'VI',      # Cessão temporária (aluguel)
        'payment': 'VII',     # Dação em pagamento
        'mining': 'VIII',     # Emissão
        'other': 'IX'         # Outras operações
    }
    
    # Códigos de tipo de pessoa
    PERSON_TYPE_CODES = {
        'cpf': '1',
        'cnpj': '2',
        'nif_pf': '3',
        'nif_pj': '4',
        'passport': '5',
        'no_nif': '6',
        'has_nif_no_person': '7'
    }
    
    def __init__(self):
        pass
    
    def generate_monthly_report(self, user_id: int, month: int, year: int) -> Dict[str, Any]:
        """
        Gera relatório mensal para a IN 1888
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
                Transaction.date <= end_date
            ).order_by(Transaction.date).all()
            
            if not transactions:
                return {
                    'success': False,
                    'message': 'Nenhuma transação encontrada para o período especificado'
                }
            
            # Calcular total de operações em BRL
            total_operations_brl = sum(
                float(t.total_brl) for t in transactions if t.total_brl
            )
            
            # Verificar se precisa declarar (acima de R$ 30.000)
            if total_operations_brl < 30000:
                return {
                    'success': False,
                    'message': f'Total de operações (R$ {total_operations_brl:,.2f}) está abaixo do limite de R$ 30.000,00'
                }
            
            # Gerar conteúdo do arquivo
            file_content = self._generate_file_content(transactions, user_id)
            
            # Calcular hash do arquivo
            file_hash = hashlib.sha256(file_content.encode('utf-8')).hexdigest()
            
            # Salvar relatório no banco
            existing_report = IN1888Report.query.filter_by(
                user_id=user_id,
                month=month,
                year=year
            ).first()
            
            if existing_report:
                existing_report.total_operations_brl = total_operations_brl
                existing_report.file_content = file_content
                existing_report.file_hash = file_hash
                report = existing_report
            else:
                report = IN1888Report(
                    user_id=user_id,
                    month=month,
                    year=year,
                    total_operations_brl=total_operations_brl,
                    file_content=file_content,
                    file_hash=file_hash
                )
                db.session.add(report)
            
            db.session.commit()
            
            return {
                'success': True,
                'report': report.to_dict(),
                'file_content': file_content,
                'total_operations': len(transactions),
                'total_operations_brl': total_operations_brl
            }
            
        except Exception as e:
            db.session.rollback()
            return {
                'success': False,
                'message': f'Erro ao gerar relatório: {str(e)}'
            }
    
    def _generate_file_content(self, transactions: List[Transaction], user_id: int) -> str:
        """
        Gera o conteúdo do arquivo conforme layout da IN 1888
        """
        lines = []
        
        for transaction in transactions:
            # Determinar tipo de registro baseado na operação
            if transaction.type in ['buy', 'sell']:
                if transaction.type == 'buy':
                    # Registro para compra (recebedor)
                    line = self._generate_buy_record(transaction)
                else:
                    # Registro para venda (transmitente)
                    line = self._generate_sell_record(transaction)
            elif transaction.type == 'swap':
                # Registro para permuta
                line = self._generate_swap_record(transaction)
            elif transaction.type in ['deposit', 'withdrawal']:
                # Registro para transferências
                line = self._generate_transfer_record(transaction)
            else:
                # Registro para outras operações
                line = self._generate_other_record(transaction)
            
            if line:
                lines.append(line)
        
        return '\n'.join(lines)
    
    def _generate_buy_record(self, transaction: Transaction) -> str:
        """
        Gera registro para operação de compra (0910 - Recebedor)
        """
        operation_date = transaction.date.strftime('%d%m%Y')
        operation_code = self.OPERATION_CODES.get(transaction.type, 'I')
        operation_value = self._format_decimal(transaction.total_brl, 14, 2)
        fee_value = self._format_decimal(transaction.fee_brl, 9, 2) if transaction.fee_brl else ''
        crypto_symbol = (transaction.to_asset or '')[:10]
        crypto_quantity = self._format_decimal(transaction.to_amount, 20, 10) if transaction.to_amount else ''
        
        # Dados do transmitente (exchange ou contraparte)
        transmitter_type = '2'  # CNPJ para exchanges
        transmitter_country = 'BR'
        transmitter_doc = ''  # Seria o CNPJ da exchange
        transmitter_ni = ''
        transmitter_name = transaction.exchange.name if transaction.exchange else 'Exchange'
        
        fields = [
            '0910',  # Tipo de registro
            operation_date,
            operation_code,
            operation_value,
            fee_value,
            crypto_symbol,
            crypto_quantity,
            transmitter_type,
            transmitter_country,
            transmitter_doc,
            transmitter_ni,
            transmitter_name[:80]
        ]
        
        return '|'.join(fields) + '|'
    
    def _generate_sell_record(self, transaction: Transaction) -> str:
        """
        Gera registro para operação de venda (0920 - Transmitente)
        """
        operation_date = transaction.date.strftime('%d%m%Y')
        operation_code = self.OPERATION_CODES.get(transaction.type, 'I')
        operation_value = self._format_decimal(transaction.total_brl, 14, 2)
        fee_value = self._format_decimal(transaction.fee_brl, 9, 2) if transaction.fee_brl else ''
        crypto_symbol = (transaction.from_asset or '')[:10]
        crypto_quantity = self._format_decimal(transaction.from_amount, 20, 10) if transaction.from_amount else ''
        
        # Dados do destino (exchange)
        exchange_name = transaction.exchange.name if transaction.exchange else 'Exchange'
        exchange_tel = ''  # Telefone da exchange
        exchange_country = 'BR'
        
        fields = [
            '0920',  # Tipo de registro
            operation_date,
            operation_code,
            operation_value,
            fee_value,
            crypto_symbol,
            crypto_quantity,
            exchange_name[:60],
            exchange_tel[:30],
            exchange_country,
            '',  # EmissorNome
            ''   # EmissorTel
        ]
        
        return '|'.join(fields) + '|'
    
    def _generate_swap_record(self, transaction: Transaction) -> str:
        """
        Gera registro para operação de permuta
        """
        # Para permuta, usar registro 0910 com código II
        operation_date = transaction.date.strftime('%d%m%Y')
        operation_code = 'II'  # Permuta
        operation_value = self._format_decimal(transaction.total_brl, 14, 2)
        fee_value = self._format_decimal(transaction.fee_brl, 9, 2) if transaction.fee_brl else ''
        crypto_symbol = (transaction.to_asset or '')[:10]
        crypto_quantity = self._format_decimal(transaction.to_amount, 20, 10) if transaction.to_amount else ''
        
        fields = [
            '0910',
            operation_date,
            operation_code,
            operation_value,
            fee_value,
            crypto_symbol,
            crypto_quantity,
            '2',  # Tipo CNPJ
            'BR',
            '',   # CNPJ
            '',   # NI
            'Permuta'
        ]
        
        return '|'.join(fields) + '|'
    
    def _generate_transfer_record(self, transaction: Transaction) -> str:
        """
        Gera registro para transferências (depósito/saque)
        """
        operation_date = transaction.date.strftime('%d%m%Y')
        operation_code = 'IV' if transaction.type == 'deposit' else 'V'
        operation_value = self._format_decimal(transaction.total_brl, 14, 2)
        fee_value = self._format_decimal(transaction.fee_brl, 9, 2) if transaction.fee_brl else ''
        
        if transaction.type == 'deposit':
            crypto_symbol = (transaction.to_asset or '')[:10]
            crypto_quantity = self._format_decimal(transaction.to_amount, 20, 10) if transaction.to_amount else ''
        else:
            crypto_symbol = (transaction.from_asset or '')[:10]
            crypto_quantity = self._format_decimal(transaction.from_amount, 20, 10) if transaction.from_amount else ''
        
        fields = [
            '0910',
            operation_date,
            operation_code,
            operation_value,
            fee_value,
            crypto_symbol,
            crypto_quantity,
            '2',  # Tipo CNPJ
            'BR',
            '',   # CNPJ
            '',   # NI
            transaction.exchange.name if transaction.exchange else 'Exchange'
        ]
        
        return '|'.join(fields) + '|'
    
    def _generate_other_record(self, transaction: Transaction) -> str:
        """
        Gera registro para outras operações
        """
        operation_date = transaction.date.strftime('%d%m%Y')
        operation_code = self.OPERATION_CODES.get(transaction.type, 'IX')
        operation_value = self._format_decimal(transaction.total_brl, 14, 2)
        fee_value = self._format_decimal(transaction.fee_brl, 9, 2) if transaction.fee_brl else ''
        crypto_symbol = (transaction.to_asset or transaction.from_asset or '')[:10]
        crypto_quantity = self._format_decimal(
            transaction.to_amount or transaction.from_amount, 20, 10
        ) if (transaction.to_amount or transaction.from_amount) else ''
        
        fields = [
            '0910',
            operation_date,
            operation_code,
            operation_value,
            fee_value,
            crypto_symbol,
            crypto_quantity,
            '2',  # Tipo CNPJ
            'BR',
            '',   # CNPJ
            '',   # NI
            transaction.notes or 'Operação'
        ]
        
        return '|'.join(fields) + '|'
    
    def _format_decimal(self, value, total_length: int, decimal_places: int) -> str:
        """
        Formata valor decimal conforme especificação da IN 1888
        Remove ponto decimal e preenche com zeros à esquerda se necessário
        """
        if value is None:
            return ''
        
        # Converter para Decimal para precisão
        if isinstance(value, (int, float)):
            decimal_value = Decimal(str(value))
        else:
            decimal_value = Decimal(value)
        
        # Multiplicar por 10^decimal_places para remover ponto decimal
        multiplier = Decimal(10) ** decimal_places
        integer_value = int(decimal_value * multiplier)
        
        # Converter para string e preencher com zeros à esquerda
        result = str(integer_value).zfill(total_length)
        
        return result
    
    def get_monthly_reports(self, user_id: int, year: int = None) -> List[Dict[str, Any]]:
        """
        Busca relatórios mensais do usuário
        """
        query = IN1888Report.query.filter_by(user_id=user_id)
        
        if year:
            query = query.filter_by(year=year)
        
        reports = query.order_by(IN1888Report.year.desc(), IN1888Report.month.desc()).all()
        
        return [report.to_dict() for report in reports]
    
    def download_report(self, report_id: int, user_id: int) -> Dict[str, Any]:
        """
        Baixa relatório específico
        """
        report = IN1888Report.query.filter_by(id=report_id, user_id=user_id).first()
        
        if not report:
            return {
                'success': False,
                'message': 'Relatório não encontrado'
            }
        
        filename = f"IN1888_{report.year}_{report.month:02d}.txt"
        
        return {
            'success': True,
            'filename': filename,
            'content': report.file_content,
            'report': report.to_dict()
        }

