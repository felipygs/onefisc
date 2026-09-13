FROM node:24-bookworm-slim

ARG APP_UID=1001
ARG APP_GID=1001
ARG PNPM_VERSION=12.3.4

RUN apt-get update \
    && apt-get install -y --no-install-recommends git \
    && npm install --global "pnpm@${PNPM_VERSION}" \
    && npm cache clean --force \
    && rm -rf /var/lib/apt/lists/*

RUN groupadd --gid "${APP_GID}" app \
    && useradd --uid "${APP_UID}" --gid "${APP_GID}" --create-home --shell /bin/bash app

COPY docker/dev-entrypoint.sh /usr/local/bin/dev-entrypoint
RUN chmod +x /usr/local/bin/dev-entrypoint

WORKDIR /app
RUN mkdir -p node_modules && chown -R app:app /app

USER app

ENTRYPOINT ["dev-entrypoint"]
CMD ["pnpm", "dev", "--host", "0.0.0.0", "--port", "3000"]
