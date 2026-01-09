#!/bin/bash
# Script de diagnóstico para problemas en extension-tokens.php

echo "=================================================="
echo "Diagnóstico: Extension Tokens System"
echo "=================================================="
echo "Fecha: $(date)"
echo ""

# 1. Verificar tabla en BD
echo "1️⃣  Verificando tabla en base de datos..."
echo "=================================================="

TABLE_EXISTS=$(mysql -u app_user -papp_pass -h localhost gestion_horas -sN -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='gestion_horas' AND TABLE_NAME='extension_tokens';" 2>&1)

if [ "$TABLE_EXISTS" = "1" ]; then
    echo "✅ Tabla extension_tokens EXISTE"
    echo ""
    echo "Estructura:"
    mysql -u app_user -papp_pass -h localhost gestion_horas -e "DESCRIBE extension_tokens;" 2>&1
else
    echo "❌ Tabla extension_tokens NO EXISTE"
    echo "Necesita ejecutar migración:"
    echo "   mysql -u app_user -papp_pass -h localhost gestion_horas < migration_extension_tokens.sql"
fi

echo ""
echo ""

# 2. Verificar código en servidor
echo "2️⃣  Verificando archivos en servidor..."
echo "=================================================="

if [ -f "/var/www/example.com/extension-tokens.php" ]; then
    echo "✅ extension-tokens.php EXISTE"
    echo "   Tamaño: $(stat -c%s /var/www/example.com/extension-tokens.php) bytes"
else
    echo "❌ extension-tokens.php NO ENCONTRADO"
fi

if [ -f "/var/www/example.com/lib.php" ]; then
    echo "✅ lib.php EXISTE"
    # Verificar que tiene las funciones
    if grep -q "function get_user_extension_tokens" /var/www/example.com/lib.php; then
        echo "   ✅ Función get_user_extension_tokens definida"
    else
        echo "   ❌ Función get_user_extension_tokens NO ENCONTRADA"
    fi
else
    echo "❌ lib.php NO ENCONTRADO"
fi

echo ""
echo ""

# 3. Verificar sintaxis PHP
echo "3️⃣  Verificando sintaxis PHP..."
echo "=================================================="

if php -l /var/www/example.com/extension-tokens.php 2>&1 | grep -q "No syntax errors"; then
    echo "✅ extension-tokens.php: Sintaxis correcta"
else
    echo "❌ extension-tokens.php: ERRORES DE SINTAXIS"
    php -l /var/www/example.com/extension-tokens.php
fi

if php -l /var/www/example.com/lib.php 2>&1 | grep -q "No syntax errors"; then
    echo "✅ lib.php: Sintaxis correcta"
else
    echo "❌ lib.php: ERRORES DE SINTAXIS"
    php -l /var/www/example.com/lib.php
fi

echo ""
echo ""

# 4. Verificar logs de Apache
echo "4️⃣  Últimos errores en Apache..."
echo "=================================================="

if [ -f "/var/log/apache2/error.log" ]; then
    echo "Últimos 20 errores relacionados con extension-tokens:"
    tail -100 /var/log/apache2/error.log | grep -A 5 -B 5 "extension-tokens" | tail -20
else
    echo "⚠️  /var/log/apache2/error.log no encontrado"
fi

echo ""
echo ""

# 5. Test de conectividad a BD
echo "5️⃣  Test de conexión a base de datos..."
echo "=================================================="

TEST_DB=$(mysql -u app_user -papp_pass -h localhost -e "SELECT 1;" 2>&1)

if echo "$TEST_DB" | grep -q "1"; then
    echo "✅ Conexión a MySQL: OK"
    
    # Test de acceso a gestion_horas
    mysql -u app_user -papp_pass -h localhost gestion_horas -e "SELECT 1;" 2>&1 > /dev/null
    if [ $? -eq 0 ]; then
        echo "✅ Acceso a base de datos gestion_horas: OK"
    else
        echo "❌ No hay acceso a base de datos gestion_horas"
    fi
else
    echo "❌ Conexión a MySQL: FALLO"
    echo "$TEST_DB"
fi

echo ""
echo "=================================================="
echo "Fin del diagnóstico"
echo "=================================================="
