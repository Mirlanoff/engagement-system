# ================================================================
#  Vue.js Frontend — Multi-stage Dockerfile
# ================================================================

FROM node:20-alpine AS builder

WORKDIR /app

COPY package*.json ./

RUN npm ci --frozen-lockfile

COPY . .

RUN npm run build

# ── Production stage (раздача через Nginx) ──────────────────────
FROM nginx:1.25-alpine AS production

COPY --from=builder /app/dist /usr/share/nginx/html
COPY docker/nginx-spa.conf /etc/nginx/conf.d/default.conf

EXPOSE 80
