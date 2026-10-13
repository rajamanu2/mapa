FROM node:24.19.0-alpine
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --omit=dev --ignore-scripts --no-audit --no-fund && mkdir -p /data && chown node:node /data
COPY *.mjs ./
USER node
ENV HOST=0.0.0.0 PORT=8789 QUEUE_DB=/data/voice-journal.sqlite
EXPOSE 8789
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 CMD ["node", "healthcheck.mjs"]
CMD ["node", "server.mjs"]
