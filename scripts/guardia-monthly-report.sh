#!/usr/bin/env bash
# guardia-monthly-report.sh — Informe mensual de días de guardia por email
#
# Propósito: El día 1 de cada mes envía un email con el desglose de guardias
#            del mes anterior: cuántas son festivos, fines de semana y laborables.
#            Listo para reenviar como comunicación a RRHH.
#
# Uso:       ./guardia-monthly-report.sh [YYYY] [MM]
#            Sin argumentos → procesa el mes anterior
#
# Cron:      0 8 1 * * /opt/GestionHorasTrabajo/scripts/guardia-monthly-report.sh
#
# Config en /opt/GestionHorasTrabajo/.env (añadir al final):
#   GUARDIA_REPORT_EMAIL=nacho@favala.es
#   SMTP_USER=homelab@favala.es
#   SMTP_PASS=<contraseña smtp>

export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"

# --- Rutas ---
APP_DIR="/opt/GestionHorasTrabajo"
ENV_FILE="$APP_DIR/.env"
LOG_FILE="/var/log/guardia-monthly-report.log"

# --- Cargar .env ---
if [[ -f "$ENV_FILE" ]]; then
    while IFS='=' read -r key val; do
        [[ "$key" =~ ^#.*$  || -z "$key" ]] && continue
        key="${key// /}"
        export "$key"="${val}"
    done < "$ENV_FILE"
fi

# --- Config BD (desde .env) ---
DB_HOST="${DB_HOST:-localhost}"
DB_NAME="${DB_NAME:-gestion_horas}"
DB_USER="${DB_USER:-app_user}"
# DB_PASS cargado del .env arriba

# --- Config email ---
# Credenciales SMTP de homelab-notify (mismo servidor DonDominio)
SMTP_URL="smtps://smtp.dondominio.com:465"
SMTP_USER="${SMTP_USER:-homelab@favala.es}"

# --- Validar credenciales obligatorias ---
if [[ -z "$SMTP_PASS" ]]; then
    echo "ERROR: SMTP_PASS no está definido en ${ENV_FILE}" >&2
    exit 1
fi
SMTP_PASS="${SMTP_PASS:-}"
EMAIL_FROM="${SMTP_USER}"
EMAIL_TO="${GUARDIA_REPORT_EMAIL:-nacho@favala.es}"

# --- Mes a procesar ---
if [[ -n "$1" && -n "$2" ]]; then
    YEAR="$1"
    MONTH=$(printf '%d' "$2")
else
    # Mes anterior al día de hoy
    YEAR=$(date -d "$(date +%Y-%m-01) -1 month" +%Y)
    MONTH=$(date -d "$(date +%Y-%m-01) -1 month" +%-m)
fi

MONTH_PAD=$(printf '%02d' "$MONTH")
DATE_START="${YEAR}-${MONTH_PAD}-01"
DATE_END=$(date -d "${DATE_START} +1 month -1 day" +%Y-%m-%d)

# Nombre del mes en español (con LC_TIME forzado o fallback inglés)
MONTH_NAME=$(LC_ALL=es_ES.UTF-8 date -d "${DATE_START}" "+%B de %Y" 2>/dev/null \
           || date -d "${DATE_START}" "+%B %Y")
# Capitalizar primera letra
MONTH_NAME="$(tr '[:lower:]' '[:upper:]' <<< "${MONTH_NAME:0:1}")${MONTH_NAME:1}"

log() { echo "$(date '+%Y-%m-%d %H:%M:%S') $*" >> "$LOG_FILE"; }

# --- Helper MySQL ---
q() {
    mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -sN -e "$1" 2>/dev/null
}

# --- Obtener user_id principal ---
USER_ID=$(q "SELECT id FROM users ORDER BY id LIMIT 1")
if [[ -z "$USER_ID" ]]; then
    log "ERROR: no se pudo obtener user_id de la BD"
    exit 1
fi

# ---
# Consulta principal: días de guardia del mes con clasificación
#   festivo   → existe entrada type='holiday' para esa fecha
#   finde     → sábado (dow=7) o domingo (dow=1)
#   laborable → el resto
# ---
GUARDIAS_TSV=$(q "
    SELECT
        h.date,
        CASE DAYOFWEEK(h.date)
            WHEN 1 THEN 'domingo'
            WHEN 2 THEN 'lunes'
            WHEN 3 THEN 'martes'
            WHEN 4 THEN 'miercoles'
            WHEN 5 THEN 'jueves'
            WHEN 6 THEN 'viernes'
            WHEN 7 THEN 'sabado'
        END AS dia_semana,
        CASE
            WHEN EXISTS (
                SELECT 1 FROM holidays f
                WHERE f.date = h.date
                  AND f.type = 'holiday'
                  AND (f.user_id IS NULL OR f.user_id = ${USER_ID})
            ) THEN 'festivo'
            WHEN DAYOFWEEK(h.date) IN (1, 7) THEN 'finde'
            ELSE 'laborable'
        END AS tipo_dia,
        COALESCE((
            SELECT f.label FROM holidays f
            WHERE f.date = h.date
              AND f.type = 'holiday'
              AND (f.user_id IS NULL OR f.user_id = ${USER_ID})
            LIMIT 1
        ), '') AS label_festivo
    FROM holidays h
    WHERE h.type = 'guardia'
      AND h.date BETWEEN '${DATE_START}' AND '${DATE_END}'
      AND (h.user_id IS NULL OR h.user_id = ${USER_ID})
    ORDER BY h.date
")

# Sin guardias → no enviar email
if [[ -z "$GUARDIAS_TSV" ]]; then
    log "INFO: sin guardias en ${MONTH_NAME} — no se envía informe"
    exit 0
fi

# --- Conteos ---
TOTAL=$(echo "$GUARDIAS_TSV" | wc -l)
NUM_FESTIVOS=$(echo "$GUARDIAS_TSV" | awk -F'\t' '$3=="festivo"' | wc -l)
NUM_FINDE=$(echo "$GUARDIAS_TSV"    | awk -F'\t' '$3=="finde"'   | wc -l)
NUM_LABORABLES=$(echo "$GUARDIAS_TSV" | awk -F'\t' '$3=="laborable"' | wc -l)

# --- HTML del email ---
cat > /tmp/guardia-report-$$.html << HTMLEOF
<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"></head>
<body style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f7fafc;margin:0;padding:20px">
<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);overflow:hidden">

  <!-- Cabecera -->
  <div style="background:linear-gradient(135deg,#2d3748,#1a202c);padding:28px 32px">
    <div style="font-size:12px;color:#a0aec0;text-transform:uppercase;letter-spacing:1px;margin-bottom:6px">Informe de guardias</div>
    <h1 style="color:#fff;margin:0;font-size:24px;font-weight:700">${MONTH_NAME}</h1>
    <div style="font-size:13px;color:#cbd5e0;margin-top:8px">${DATE_START} &mdash; ${DATE_END}</div>
  </div>

  <!-- Tarjetas resumen -->
  <div style="display:flex;border-bottom:1px solid #e2e8f0">
    <div style="flex:1;padding:20px;text-align:center;border-right:1px solid #e2e8f0">
      <div style="font-size:32px;font-weight:700;color:#2d3748">${TOTAL}</div>
      <div style="font-size:12px;color:#718096;margin-top:4px;text-transform:uppercase;letter-spacing:.5px">Total días</div>
    </div>
    <div style="flex:1;padding:20px;text-align:center;border-right:1px solid #e2e8f0">
      <div style="font-size:32px;font-weight:700;color:#e53e3e">${NUM_FESTIVOS}</div>
      <div style="font-size:12px;color:#718096;margin-top:4px;text-transform:uppercase;letter-spacing:.5px">Festivos</div>
    </div>
    <div style="flex:1;padding:20px;text-align:center;border-right:1px solid #e2e8f0">
      <div style="font-size:32px;font-weight:700;color:#d69e2e">${NUM_FINDE}</div>
      <div style="font-size:12px;color:#718096;margin-top:4px;text-transform:uppercase;letter-spacing:.5px">Fin de semana</div>
    </div>
    <div style="flex:1;padding:20px;text-align:center">
      <div style="font-size:32px;font-weight:700;color:#2b6cb0">${NUM_LABORABLES}</div>
      <div style="font-size:12px;color:#718096;margin-top:4px;text-transform:uppercase;letter-spacing:.5px">Laborables</div>
    </div>
  </div>

</div>
</body>
</html>
HTMLEOF

# --- Enviar email via curl SMTPS ---
SUBJECT="Guardias ${MONTH_NAME}: ${TOTAL} días (${NUM_FESTIVOS} festivos, ${NUM_FINDE} finde, ${NUM_LABORABLES} laborables)"

# Construir mensaje RFC 2822 completo en fichero temporal
TMP_MSG="/tmp/guardia-report-msg-$$.eml"
cat > "$TMP_MSG" << EMLEOF
From: GestionHoras <${EMAIL_FROM}>
To: ${EMAIL_TO}
Subject: ${SUBJECT}
MIME-Version: 1.0
Content-Type: text/html; charset=UTF-8
Content-Transfer-Encoding: 8bit

$(cat /tmp/guardia-report-$$.html)
EMLEOF

curl -s --ssl-reqd \
    --url "${SMTP_URL}" \
    --user "${SMTP_USER}:${SMTP_PASS}" \
    --mail-from "${EMAIL_FROM}" \
    --mail-rcpt "${EMAIL_TO}" \
    --upload-file "$TMP_MSG"

CURL_EXIT=$?
rm -f "/tmp/guardia-report-$$.html" "$TMP_MSG"

if [[ $CURL_EXIT -eq 0 ]]; then
    log "OK: informe ${MONTH_NAME} enviado a ${EMAIL_TO} | ${TOTAL} guardias: ${NUM_FESTIVOS}F / ${NUM_FINDE}W / ${NUM_LABORABLES}L"
else
    log "ERROR: fallo al enviar (curl exit ${CURL_EXIT}) | informe ${MONTH_NAME}"
    exit 1
fi
