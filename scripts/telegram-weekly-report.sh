#!/bin/bash
# =============================================================================
# telegram-weekly-report.sh — Informe semanal de horas (cada viernes a las 17h)
#
# Envía por Telegram un resumen de horas trabajadas esta semana vs. objetivo.
# Cron: 0 17 * * 5 root /opt/GestionHorasTrabajo/scripts/telegram-weekly-report.sh
# =============================================================================

export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"

# --- Configuración ---
APP_DIR="/opt/GestionHorasTrabajo"
BOT_TOKEN="1844857402:AAHopQk1mTi4nE3ph4tIPrZ6ZQvg8N3PQco"
CHAT_ID="216917915"
LOG_FILE="/var/log/gestion-horas-telegram-weekly.log"

# --- Cargar variables de la app (.env) ---
[[ -f "$APP_DIR/.env" ]] && source "$APP_DIR/.env"

DB_USER_VAL="${DB_USER:-app_user}"
DB_PASS_VAL="${DB_PASS:-}"
DB_NAME_VAL="${DB_NAME:-gestion_horas}"

# --- Helpers ---
log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" >> "$LOG_FILE"; }

send_telegram() {
    local msg="$1"
    curl -s -X POST \
        "https://api.telegram.org/bot${BOT_TOKEN}/sendMessage" \
        --data-urlencode "chat_id=${CHAT_ID}" \
        --data-urlencode "text=${msg}" \
        --data-urlencode "parse_mode=HTML" \
        -o /dev/null
}

# --- Calcular rango de la semana ---
DOW=$(date '+%u')   # 1=Lunes, 7=Domingo
MONDAY=$(date -d "-$(( DOW - 1 )) days" '+%Y-%m-%d')
TODAY=$(date '+%Y-%m-%d')
YEAR=$(date '+%Y')
SEMANA_NUM=$(date '+%V')

log "Generando informe semana ${SEMANA_NUM} (lunes: ${MONDAY}, hoy: ${TODAY})"

# --- Horas trabajadas esta semana ---
# Se consulta el usuario admin (is_admin=1) como usuario principal de la app.
# Para apps multiusuario, adaptar la query al user_id deseado.
RESULT=$(mysql -u"${DB_USER_VAL}" -p"${DB_PASS_VAL}" "${DB_NAME_VAL}" -sN \
    --execute="
        SELECT
            COUNT(DISTINCT e.date),
            COALESCE(SUM(
                TIMESTAMPDIFF(MINUTE, e.start, COALESCE(e.end, CURTIME()))
                - COALESCE(TIMESTAMPDIFF(MINUTE, e.coffee_out, e.coffee_in), 0)
                - COALESCE(TIMESTAMPDIFF(MINUTE, e.lunch_out,  e.lunch_in),  0)
            ), 0)
        FROM entries e
        JOIN users u ON e.user_id = u.id AND u.is_admin = 1
        WHERE e.date BETWEEN '${MONDAY}' AND '${TODAY}'
          AND e.start IS NOT NULL
          AND e.absence_type IS NULL;
    " 2>/dev/null)

if [[ -z "$RESULT" ]]; then
    log "ERROR: Sin resultado de la BD."
    send_telegram "⚠️ <b>GestionHoras</b>: No se pudo generar el informe semanal (error BD)."
    exit 1
fi

DIAS_TRABAJADOS=$(echo "$RESULT" | awk '{print $1}')
MINUTOS_TOTAL=$(echo "$RESULT"   | awk '{print $2}')

HORAS_TOTAL=$(( MINUTOS_TOTAL / 60 ))
MINS_RESTO=$(( MINUTOS_TOTAL % 60 ))

# --- Objetivo semanal desde year_config ---
MES_ACTUAL=$(date '+%m')
if [[ "$MES_ACTUAL" -ge 6 && "$MES_ACTUAL" -le 9 ]]; then
    CAMPO_CONFIG="expected_daily_hours_summer"
    TEMPORADA="verano"
else
    CAMPO_CONFIG="expected_daily_hours_winter"
    TEMPORADA="invierno"
fi

HORAS_DIA=$(mysql -u"${DB_USER_VAL}" -p"${DB_PASS_VAL}" "${DB_NAME_VAL}" -sN \
    --execute="SELECT COALESCE(${CAMPO_CONFIG}, 7.65) FROM year_configs WHERE year=${YEAR} LIMIT 1;" \
    2>/dev/null)
HORAS_DIA="${HORAS_DIA:-7.65}"

# Objetivo semanal en minutos (5 días laborables)
OBJ_MINUTOS=$(echo "scale=0; ${HORAS_DIA} * 5 * 60 / 1" | bc 2>/dev/null || echo "2295")
OBJ_HORAS=$(( OBJ_MINUTOS / 60 ))
OBJ_MINS=$(( OBJ_MINUTOS % 60 ))

# --- Diferencia ---
DIFF_MINS=$(( MINUTOS_TOTAL - OBJ_MINUTOS ))
if [[ $DIFF_MINS -ge 0 ]]; then
    DIFF_SIGN="+"
else
    DIFF_SIGN="−"
    DIFF_MINS=$(( -DIFF_MINS ))
fi
DIFF_H=$(( DIFF_MINS / 60 ))
DIFF_M=$(( DIFF_MINS % 60 ))

# --- Emoji de estado ---
if [[ $MINUTOS_TOTAL -ge $OBJ_MINUTOS ]]; then
    EMOJI="✅"
    STATUS_TXT="Semana completada 🎉"
elif [[ $(( OBJ_MINUTOS - MINUTOS_TOTAL )) -le 60 ]]; then
    EMOJI="🟡"
    STATUS_TXT="Casi completada"
else
    FALTAN_H=$(( (OBJ_MINUTOS - MINUTOS_TOTAL) / 60 ))
    FALTAN_M=$(( (OBJ_MINUTOS - MINUTOS_TOTAL) % 60 ))
    EMOJI="🔴"
    STATUS_TXT="Faltan ${FALTAN_H}h${FALTAN_M}m"
fi

# --- Construir mensaje ---
MSG="${EMOJI} <b>Informe Semanal — Semana ${SEMANA_NUM}/${YEAR}</b>
📅 Del ${MONDAY} al ${TODAY}

⏱ <b>Horas trabajadas:</b> ${HORAS_TOTAL}h${MINS_RESTO}m
🎯 <b>Objetivo (${TEMPORADA}):</b> ${OBJ_HORAS}h${OBJ_MINS}m
📊 <b>Diferencia:</b> ${DIFF_SIGN}${DIFF_H}h${DIFF_M}m
📆 <b>Días trabajados:</b> ${DIAS_TRABAJADOS}/5

<i>${STATUS_TXT}</i>
🔗 <a href=\"https://calendar.favala.es\">Ver registro completo</a>"

send_telegram "$MSG"
log "OK — ${HORAS_TOTAL}h${MINS_RESTO}m / ${OBJ_HORAS}h${OBJ_MINS}m — semana ${SEMANA_NUM}"
