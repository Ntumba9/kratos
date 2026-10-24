#!/bin/sh
# KRATOS one-shot setup for an Ubuntu 22.04/24.04 VM (Oracle Cloud Always Free).
#
#   git clone https://github.com/Ntumba9/kratos.git && cd kratos
#   KRATOS_FRONTEND_URL=https://<your-app>.vercel.app sh infra/oracle/setup.sh
#
# Installs Docker, opens ports 80/443 in the host firewall, generates
# .env.prod with random secrets (only on first run) and starts the stack.
# The VCN security list must also allow ingress on TCP 80 and 443.
set -eu

: "${KRATOS_FRONTEND_URL:?set KRATOS_FRONTEND_URL, e.g. https://kratos.vercel.app}"

if ! command -v docker >/dev/null 2>&1; then
  curl -fsSL https://get.docker.com | sudo sh
  sudo usermod -aG docker "$USER"
fi

# Oracle's Ubuntu images ship iptables rules that reject everything but SSH.
for port in 80 443; do
  sudo iptables -C INPUT -p tcp --dport "$port" -j ACCEPT 2>/dev/null \
    || sudo iptables -I INPUT 6 -m state --state NEW -p tcp --dport "$port" -j ACCEPT
done
command -v netfilter-persistent >/dev/null 2>&1 && sudo netfilter-persistent save

if [ ! -f .env.prod ]; then
  ip="$(curl -fsS https://api.ipify.org)"
  rnd() { openssl rand -hex 24; }
  umask 077
  cat > .env.prod <<ENV
KRATOS_API_HOST=${ip}.sslip.io
KRATOS_FRONTEND_URL=${KRATOS_FRONTEND_URL}
KRATOS_FRONTEND_HOST=$(echo "$KRATOS_FRONTEND_URL" | sed -E 's#^https?://##; s#/.*$##')
KRATOS_APP_KEY=base64:$(openssl rand -base64 32)
KRATOS_POSTGRES_SUPERUSER_PASSWORD=$(rnd)
KRATOS_DB_OWNER_PASSWORD=$(rnd)
KRATOS_DB_APP_PASSWORD=$(rnd)
KRATOS_REDIS_PASSWORD=$(rnd)
KRATOS_MIND_SHARED_SECRET=$(openssl rand -hex 32)
ENV
  echo "Generated .env.prod (keep it private; it is git-ignored)."
fi

sudo docker compose --env-file .env.prod -f docker-compose.prod.yml up -d --build
. ./.env.prod
echo "API: https://${KRATOS_API_HOST}/api/v1/health"
