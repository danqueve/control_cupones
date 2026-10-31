#!/bin/bash
# scripts/test_api_movimientos.sh — Smoke test de api/v1/movimientos.php
#
# Uso:
#   BASE_URL=http://localhost:8089 TOKEN=xxx ./scripts/test_api_movimientos.sh
#   (sin env vars, usa los valores por defecto de abajo, pensados para WAMP local)
#
# Requiere: curl. El server tiene que estar corriendo
# (php -S localhost:8089 desde la raiz del repo).

BASE_URL="${BASE_URL:-http://localhost:8089}"
TOKEN="${TOKEN:-CAMBIAR_EN_config.php}"
EP="$BASE_URL/api/v1/movimientos.php"

echo "=== 1. Sin token (esperado 401) ==="
curl -s -w "\nHTTP %{http_code}\n" "$EP?desde=2026-10-01&hasta=2026-10-07"

echo ""
echo "=== 2. Token invalido (esperado 403) ==="
curl -s -w "\nHTTP %{http_code}\n" -H "Authorization: Bearer token-trucho" "$EP?desde=2026-10-01&hasta=2026-10-07"

echo ""
echo "=== 3. Fechas invalidas (esperado 400) ==="
curl -s -w "\nHTTP %{http_code}\n" -H "Authorization: Bearer $TOKEN" "$EP?desde=fecha-mala&hasta=2026-10-07"

echo ""
echo "=== 4. Rango > 100 dias (esperado 400) ==="
curl -s -w "\nHTTP %{http_code}\n" -H "Authorization: Bearer $TOKEN" "$EP?desde=2026-01-01&hasta=2026-10-07"

echo ""
echo "=== 5. POST en vez de GET (esperado 405) ==="
curl -s -X POST -w "\nHTTP %{http_code}\n" -H "Authorization: Bearer $TOKEN" "$EP?desde=2026-10-01&hasta=2026-10-02"

echo ""
echo "=== 6. OK — rango real de una semana ==="
curl -s -H "Authorization: Bearer $TOKEN" "$EP?desde=2026-10-19&hasta=2026-10-25" | head -c 500
echo ""
echo "..."
