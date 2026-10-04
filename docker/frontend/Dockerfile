# syntax=docker/dockerfile:1.7

FROM node:22-alpine AS build

ENV NODE_OPTIONS=--max-old-space-size=2048

WORKDIR /app

COPY frontend/package.json frontend/package-lock.json ./

RUN --mount=type=cache,target=/root/.npm \
    npm ci --no-audit --no-fund --legacy-peer-deps

COPY frontend/ ./

RUN --mount=type=cache,target=/root/.npm \
    --mount=type=cache,target=/app/.angular \
    npm run build

FROM nginx:1.29-alpine AS runtime

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/dist/frontend/browser /usr/share/nginx/html

EXPOSE 80

CMD ["nginx", "-g", "daemon off;"]
