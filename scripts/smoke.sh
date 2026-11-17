#!/usr/bin/env bash
# Teste de fumaça de um Warren em execução: o login protege tudo, entrar funciona, a API de cada conexão responde.
#
#   SMOKE_EMAIL=you@example.com SMOKE_PASSWORD=... scripts/smoke.sh [base_url] [--expect-traffic]
#
# --expect-traffic também espera a escuta ficar ativa e as mensagens aparecerem (use com a pilha de demonstração).
set -euo pipefail

BASE="${1:-http://localhost:8080}"
EXPECT_TRAFFIC="${2:-}"
: "${SMOKE_EMAIL:?defina SMOKE_EMAIL}"
: "${SMOKE_PASSWORD:?defina SMOKE_PASSWORD}"

JAR="$(mktemp)"
trap 'rm -f "$JAR"' EXIT

pass() { echo "  ok   $*"; }
fail() { echo "  FAIL $*" >&2; exit 1; }
# eq "what is checked" expected actual
eq() { if [ "$2" = "$3" ]; then pass "$1"; else fail "$1 (esperado '$2', recebido '$3')"; fi; }
status() { curl -s -o /dev/null -w '%{http_code}' "$@"; }
xsrf() { python3 - "$JAR" <<'PY'
import sys, urllib.parse
tokens = [line.rstrip("\n").split("\t")[-1] for line in open(sys.argv[1]) if "XSRF-TOKEN" in line]
print(urllib.parse.unquote(tokens[-1]) if tokens else "")
PY
}
json() { python3 -c "import json,sys; d=json.load(sys.stdin); print($1)"; }

echo "Teste de fumaça em $BASE"

eq "/up está saudável" 200 "$(status "$BASE/up")"
eq "visitantes são redirecionados ao login" 302 "$(status "$BASE/")"
for path in /api/brokers /api/b/1/topology /api/b/1/events /api/b/1/messages/1; do
    eq "visitante em $path -> 401" 401 "$(status -H 'Accept: application/json' "$BASE$path")"
done

curl -s -c "$JAR" -b "$JAR" -o /dev/null "$BASE/login"
eq "login sem token CSRF é recusado" 419 "$(status -c "$JAR" -b "$JAR" -X POST -d "email=$SMOKE_EMAIL&password=$SMOKE_PASSWORD" "$BASE/login")"

status -c "$JAR" -b "$JAR" -X POST -H "X-XSRF-TOKEN: $(xsrf)" -d "email=$SMOKE_EMAIL&password=definitely-wrong" "$BASE/login" >/dev/null
eq "senha errada não entra" 401 "$(status -c "$JAR" -b "$JAR" -H 'Accept: application/json' "$BASE/api/brokers")"

LOCATION="$(curl -s -c "$JAR" -b "$JAR" -o /dev/null -w '%{redirect_url}' -X POST -H "X-XSRF-TOKEN: $(xsrf)" -d "email=$SMOKE_EMAIL&password=$SMOKE_PASSWORD" "$BASE/login")"
eq "a senha certa entra" "$BASE/" "$LOCATION"

# A primeira conexão vem do RABBITMQ_* na subida (warren:broker --from-env); a API de cada uma fica em /api/b/{id}.
BROKERS="$(curl -s -c "$JAR" -b "$JAR" -H 'Accept: application/json' "$BASE/api/brokers")"
if ! ID="$(echo "$BROKERS" | json "d[0]['id']" 2>/dev/null)"; then
    fail "/api/brokers não listou nenhuma conexão: ${BROKERS:0:200}"
fi
pass "conexão cadastrada: $(echo "$BROKERS" | json "d[0]['name']")"
API="$BASE/api/b/$ID"

TOPOLOGY="$(curl -s -c "$JAR" -b "$JAR" -H 'Accept: application/json' "$API/topology")"
if SUMMARY="$(echo "$TOPOLOGY" | json "f\"{len(d['queues'])} filas, {len(d['exchanges'])} exchanges, {len(d['workers'])} {'worker' if len(d['workers']) == 1 else 'workers'}\"" 2>/dev/null)"; then
    pass "topologia: $SUMMARY"
else
    fail "$API/topology não devolveu uma topologia: ${TOPOLOGY:0:200}"
fi

if [ "$EXPECT_TRAFFIC" = "--expect-traffic" ]; then
    LIVE=0
    for _ in $(seq 1 30); do
        EVENTS="$(curl -s -c "$JAR" -b "$JAR" -H 'Accept: application/json' "$API/events")"
        LIVE="$(echo "$EVENTS" | json "int(d['tap']['active'] and len(d['messages']) > 0)" 2>/dev/null || echo 0)"
        [ "$LIVE" = 1 ] && break
        sleep 2
    done
    eq "a escuta está ativa e capturou mensagens" 1 "$LIVE"
    pass "$(echo "$EVENTS" | json "len(d['messages'])") mensagens, rastreio $(echo "$EVENTS" | json "'ligado' if d['tap']['tracing'] else 'desligado'")"

    # O gerador de tráfego também declara filas e roda um worker: os dois precisam aparecer na topologia.
    SHOWN=0
    for _ in $(seq 1 30); do
        TOPOLOGY="$(curl -s -c "$JAR" -b "$JAR" -H 'Accept: application/json' "$API/topology")"
        SHOWN="$(echo "$TOPOLOGY" | json "int(len(d['queues']) > 0 and len(d['workers']) > 0)" 2>/dev/null || echo 0)"
        [ "$SHOWN" = 1 ] && break
        sleep 2
    done
    eq "filas e um worker aparecem na topologia" 1 "$SHOWN"
    pass "$(echo "$TOPOLOGY" | json "f\"{len(d['queues'])} filas, {len(d['workers'])} {'worker' if len(d['workers']) == 1 else 'workers'}, {sum(w['consumers'] for w in d['workers'])} consumidores\"")"
else
    eq "eventos respondem" 200 "$(status -c "$JAR" -b "$JAR" -H 'Accept: application/json' "$API/events")"
    eq "métricas respondem" 200 "$(status -c "$JAR" -b "$JAR" -H 'Accept: application/json' "$API/metrics?range=1h")"
fi

eq "sair redireciona" 302 "$(status -c "$JAR" -b "$JAR" -X POST -H "X-XSRF-TOKEN: $(xsrf)" "$BASE/logout")"
eq "depois de sair a API volta a ficar fechada" 401 "$(status -c "$JAR" -b "$JAR" -H 'Accept: application/json' "$API/topology")"

echo "Todas as verificações passaram."
