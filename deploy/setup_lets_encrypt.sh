#!/bin/bash

# Script para instalar Let's Encrypt (Certbot) y generar certificado válido
# Para calendar.favala.es

set -e

echo "🔐 Instalando Certbot para Let's Encrypt..."

# Detectar el sistema operativo
if command -v apt-get &> /dev/null; then
    echo "✓ Sistema Debian/Ubuntu detectado"
    sudo apt-get update
    sudo apt-get install -y certbot python3-certbot-apache
elif command -v yum &> /dev/null; then
    echo "✓ Sistema RedHat/CentOS detectado"
    sudo yum install -y certbot python3-certbot-apache
else
    echo "❌ Sistema no soportado. Instala Certbot manualmente"
    exit 1
fi

echo ""
echo "📝 Generando certificado para calendar.favala.es..."
echo ""
echo "Requisitos:"
echo "1. El dominio calendar.favala.es debe apuntar a este servidor"
echo "2. El puerto 80 debe estar accesible desde internet"
echo "3. Apache debe estar corriendo"
echo ""

read -p "¿Quieres proceder? (s/n) " -n 1 -r
echo
if [[ $REPLY =~ ^[Ss]$ ]]; then
    sudo certbot certonly \
        --apache \
        -d calendar.favala.es \
        --non-interactive \
        --agree-tos \
        -m admin@calendar.favala.es \
        --rsa-key-size 2048
    
    echo ""
    echo "✅ Certificado generado exitosamente"
    echo ""
    echo "Ubicación de los archivos:"
    echo "  Certificado: /etc/letsencrypt/live/calendar.favala.es/fullchain.pem"
    echo "  Clave privada: /etc/letsencrypt/live/calendar.favala.es/privkey.pem"
    echo ""
    echo "Ahora necesitas actualizar la configuración de Apache."
else
    echo "Abortado."
    exit 0
fi
