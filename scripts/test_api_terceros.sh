#!/bin/bash
# scripts/test_api_terceros.sh — Smoke test de los campos de pagadores terceros
# (dni_cliente_sas / es_tercero) en api/v1/movimientos.php y api/v1/comprobantes.php.
#
# A diferencia de test_api_movimientos.sh / test_api_comprobantes.sh (que solo
# imprimen la respuesta), este script FALLA (exit 1) si algo no cierra.
#
# Uso:
#   BASE_URL=http://localhost:8089 TOKEN=xxx DESDE=2026-10-01 HASTA=2026-10-30 \
#     ./scripts/test_api_terceros.sh
#
# Para que el chequeo puntual de un vínculo sea exacto (no solo "el campo
# existe"), pasá también el DNI de un pagador tercero vigente y el del
# cliente al que está vinculado (ver terceros.php o `SELECT * FROM
# pagadores_terceros WHERE activo = 1`):
#   DNI_TERCERO=27333444 DNI_CLIENTE=30111222 ./scripts/test_api_terceros.sh
#
# Requiere: curl, jq. El server tiene que estar corriendo
# (php -S localhost:8089 desde la raiz del repo).

BASE_URL="${BASE_URL:-http://localhost:8089}"
TOKEN="${TOKEN:-CAMBIAR_EN_config.php}"
DESDE="${DESDE:-$(date -d '90 days ago' +%Y-%m-%d 2>/dev/null || date -v-90d +%Y-%m-%d)}"
HASTA="${HASTA:-$(date +%Y-%m-%d)}"
DNI_TERCERO="${DNI_TERCERO:-}"
DNI_CLIENTE="${DNI_CLIENTE:-}"

fallos=0
ok() { echo "[OK] $1"; }
fallo() { echo "[FALLO] $1"; fallos=$((fallos + 1)); }

echo "=== api/v1/movimientos.php?desde=$DESDE&hasta=$HASTA ==="
MOVS=$(curl -s -H "Authorization: Bearer $TOKEN" "$BASE_URL/api/v1/movimientos.php?desde=$DESDE&hasta=$HASTA")
if ! echo "$MOVS" | jq -e '.data' > /dev/null 2>&1; then
    fallo "la respuesta no es JSON válido con .data: $(echo "$MOVS" | head -c 200)"
else
    if echo "$MOVS" | jq -e '.data | length > 0' > /dev/null 2>&1; then
        if echo "$MOVS" | jq -e '.data[0] | has("dni_cliente_sas") and has("es_tercero")' > /dev/null 2>&1; then
            ok "movimientos trae los campos dni_cliente_sas / es_tercero"
        else
            fallo "falta dni_cliente_sas o es_tercero en las filas de movimientos"
        fi
        if echo "$MOVS" | jq -e '[.data[] | select(.es_tercero == false)] | map(.dni_cliente_sas == null) | all' > /dev/null 2>&1; then
            ok "cuando es_tercero=false, dni_cliente_sas viene null (sin falsos positivos)"
        else
            fallo "hay filas con es_tercero=false pero dni_cliente_sas distinto de null"
        fi
    else
        echo "  (sin movimientos en el rango — subí un resumen o ajustá DESDE/HASTA para probar con datos)"
    fi
    if [ -n "$DNI_TERCERO" ] && [ -n "$DNI_CLIENTE" ]; then
        if echo "$MOVS" | jq -e --argjson t "$DNI_TERCERO" --argjson c "$DNI_CLIENTE" \
            '[.data[] | select(.dni == $t)] | length > 0 and (map(.es_tercero == true and .dni_cliente_sas == $c) | all)' \
            > /dev/null 2>&1; then
            ok "las transferencias del pagador $DNI_TERCERO se atribuyen al cliente $DNI_CLIENTE"
        else
            fallo "no se encontraron transferencias del pagador $DNI_TERCERO atribuidas al cliente $DNI_CLIENTE en este rango"
        fi
    fi
fi

echo ""
echo "=== api/v1/comprobantes.php?desde=$DESDE&hasta=$HASTA ==="
COMP=$(curl -s -H "Authorization: Bearer $TOKEN" "$BASE_URL/api/v1/comprobantes.php?desde=$DESDE&hasta=$HASTA")
if ! echo "$COMP" | jq -e '.data' > /dev/null 2>&1; then
    fallo "la respuesta no es JSON válido con .data: $(echo "$COMP" | head -c 200)"
else
    if echo "$COMP" | jq -e '.data | length > 0' > /dev/null 2>&1; then
        if echo "$COMP" | jq -e '.data[0] | has("dni_cliente_sas") and has("es_tercero")' > /dev/null 2>&1; then
            ok "comprobantes trae los campos dni_cliente_sas / es_tercero"
        else
            fallo "falta dni_cliente_sas o es_tercero en las filas de comprobantes"
        fi
        if echo "$COMP" | jq -e '[.data[] | select(.es_tercero == false)] | map(.dni_cliente_sas == null) | all' > /dev/null 2>&1; then
            ok "cuando es_tercero=false, dni_cliente_sas viene null (sin falsos positivos)"
        else
            fallo "hay filas con es_tercero=false pero dni_cliente_sas distinto de null"
        fi
    else
        echo "  (sin comprobantes en el rango — ajustá DESDE/HASTA para probar con datos)"
    fi
fi

echo ""
if [ "$fallos" -eq 0 ]; then
    echo "=== Todos los chequeos OK ==="
    exit 0
else
    echo "=== $fallos chequeo(s) FALLARON — revisar arriba ==="
    exit 1
fi
