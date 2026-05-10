#!/bin/bash

# Script de instalação automática dos serviços Binance
# Este script cria a estrutura de diretórios e copia os arquivos necessários

echo "🚀 Instalando serviços Binance otimizados..."

# Cores para output
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

# Função para imprimir mensagens coloridas
print_status() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

print_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Verificar se estamos no diretório correto (raiz do projeto Laravel)
if [ ! -f "artisan" ]; then
    print_error "Este script deve ser executado na raiz do projeto Laravel"
    exit 1
fi

print_status "Verificando estrutura do projeto..."

# Criar diretório app/Services se não existir
if [ ! -d "app/Services" ]; then
    print_status "Criando diretório app/Services..."
    mkdir -p app/Services
    print_success "Diretório app/Services criado"
else
    print_success "Diretório app/Services já existe"
fi

# Verificar se os arquivos de serviço existem no diretório atual
SERVICES_DIR="/home/ubuntu"

if [ ! -f "${SERVICES_DIR}/BinanceConvertService.php" ]; then
    print_error "Arquivo BinanceConvertService.php não encontrado em ${SERVICES_DIR}"
    exit 1
fi

if [ ! -f "${SERVICES_DIR}/BinancePriceOptimizer.php" ]; then
    print_error "Arquivo BinancePriceOptimizer.php não encontrado em ${SERVICES_DIR}"
    exit 1
fi

# Copiar arquivos de serviço
print_status "Copiando BinanceConvertService.php..."
cp "${SERVICES_DIR}/BinanceConvertService.php" app/Services/
print_success "BinanceConvertService.php copiado"

print_status "Copiando BinancePriceOptimizer.php..."
cp "${SERVICES_DIR}/BinancePriceOptimizer.php" app/Services/
print_success "BinancePriceOptimizer.php copiado"

# Verificar permissões
print_status "Verificando permissões dos arquivos..."
chmod 644 app/Services/BinanceConvertService.php
chmod 644 app/Services/BinancePriceOptimizer.php
print_success "Permissões configuradas"

# Fazer backup do TransactionController atual
if [ -f "app/Http/Controllers/TransactionController.php" ]; then
    print_status "Fazendo backup do TransactionController atual..."
    cp app/Http/Controllers/TransactionController.php app/Http/Controllers/TransactionController_backup_$(date +%Y%m%d_%H%M%S).php
    print_success "Backup criado"
fi

# Verificar se o cache está configurado
print_status "Verificando configuração de cache..."
if grep -q "CACHE_DRIVER" .env; then
    print_success "Cache configurado no .env"
else
    print_warning "CACHE_DRIVER não encontrado no .env - recomendado configurar para melhor performance"
fi

# Limpar cache do Laravel
print_status "Limpando cache do Laravel..."
php artisan cache:clear > /dev/null 2>&1
php artisan config:clear > /dev/null 2>&1
print_success "Cache limpo"

# Verificar se os serviços foram instalados corretamente
print_status "Verificando instalação..."

if [ -f "app/Services/BinanceConvertService.php" ] && [ -f "app/Services/BinancePriceOptimizer.php" ]; then
    print_success "Todos os serviços foram instalados com sucesso!"
else
    print_error "Erro na instalação - alguns arquivos não foram copiados"
    exit 1
fi

echo ""
echo "🎉 Instalação concluída com sucesso!"
echo ""
echo "📋 Próximos passos:"
echo "1. Editar app/Http/Controllers/TransactionController.php"
echo "2. Seguir as instruções em TransactionController_Integration.php"
echo "3. Testar a importação"
echo ""
echo "📁 Arquivos criados:"
echo "   - app/Services/BinanceConvertService.php"
echo "   - app/Services/BinancePriceOptimizer.php"
echo ""
echo "📁 Arquivos de referência:"
echo "   - TransactionController_Integration.php (instruções)"
echo "   - TransactionController_backup_* (backup automático)"
echo ""
echo "🔧 Para aplicar as modificações no TransactionController:"
echo "   Consulte o arquivo TransactionController_Integration.php"
echo ""
