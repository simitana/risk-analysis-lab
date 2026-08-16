#!/usr/bin/env bash
# Sobe redis + os dois serviços (recursos idênticos), aguarda ficarem
# saudáveis e roda a mesma carga k6 contra cada um, sequencialmente, para
# não competirem por CPU/rede entre si durante a medição.
set -euo pipefail

cd "$(dirname "$0")/.."

VUS="${VUS:-300}"
SUSTAIN="${SUSTAIN:-60s}"
RESULTS_DIR="load-test/results"
TIMESTAMP="$(date +%Y%m%d-%H%M%S)"

mkdir -p "$RESULTS_DIR"
# O container do k6 roda como usuário não-root (UID diferente do host),
# então precisa de permissão de escrita explícita neste diretório.
chmod 777 "$RESULTS_DIR"

echo "==> Subindo redis + serviços (build + recursos idênticos)..."
docker compose up -d --build redis php-swoole kotlin-coroutines

echo "==> Aguardando health checks..."
for svc in risk-lab-php-swoole risk-lab-kotlin; do
  until [ "$(docker inspect -f '{{.State.Health.Status}}' "$svc" 2>/dev/null)" = "healthy" ]; do
    sleep 2
  done
  echo "  - $svc: healthy"
done

run_k6() {
  local name="$1"
  local url="$2"
  local out_file="${name}-${TIMESTAMP}.json"

  echo ""
  echo "==> Rodando k6 contra ${name} (${url}) — VUS=${VUS} SUSTAIN=${SUSTAIN}"
  docker run --rm --network risk-lab-net \
    -e TARGET_URL="$url" \
    -e VUS="$VUS" \
    -e SUSTAIN="$SUSTAIN" \
    -v "$(pwd)/load-test/k6:/scripts" \
    -v "$(pwd)/$RESULTS_DIR:/results" \
    grafana/k6 run --summary-export="/results/${out_file}" /scripts/scenario.js
}

run_k6 "php-swoole" "http://risk-lab-php-swoole:9501"
run_k6 "kotlin-coroutines" "http://risk-lab-kotlin:9502"

echo ""
echo "==> Resultados salvos em ${RESULTS_DIR}/*-${TIMESTAMP}.json"
echo "==> Comparação rápida:"

if command -v jq >/dev/null 2>&1; then
  for f in "$RESULTS_DIR"/*-"$TIMESTAMP".json; do
    echo "--- $f"
    jq -r '
      .metrics as $m |
      "  reqs totais:      \($m.http_reqs.count // "n/a")",
      "  req/s (médio):    \($m.http_reqs.rate // "n/a")",
      "  latência p95 ms:  \($m.http_req_duration["p(95)"] // "n/a")",
      "  latência p99 ms:  \($m.http_req_duration["p(99)"] // "n/a")",
      "  taxa de falha:    \($m.http_req_failed.rate // "n/a")"
    ' "$f"
  done
else
  echo "  (instale 'jq' para ver o resumo formatado; os JSONs brutos estão em ${RESULTS_DIR})"
fi
