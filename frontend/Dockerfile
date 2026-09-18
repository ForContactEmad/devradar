# syntax=docker/dockerfile:1

# ─────────────────────────────────────────────────────────────────────────────
# DevRadar frontend — Nuxt 4
#
# Three targets, because development and deployment want opposite things:
#
#   deps    - dependencies only, so a source change does not reinstall them
#   dev     - source mounted, HMR, dev dependencies present
#   runtime - the built .output only, no source, no dev dependencies
#
# No secrets here. Anything the browser needs arrives at runtime through
# NUXT_PUBLIC_* environment variables; baking one into a layer would publish
# it to anyone who can pull the image.
# ─────────────────────────────────────────────────────────────────────────────

FROM node:22-alpine AS deps
WORKDIR /app
# Only the manifests, so this layer is cached until dependencies actually
# change rather than on every edit.
COPY package.json package-lock.json* ./
RUN npm ci --no-audit --no-fund || npm install --no-audit --no-fund


FROM deps AS dev
WORKDIR /app
ENV NODE_ENV=development

# node_modules is installed by the `deps` stage as root, and this stage drops
# to the unprivileged `node` user. Without this chown the user cannot write to
# its own dependency tree, and `npm ci` inside the container fails with EACCES
# on /app/node_modules/.bin.
#
# It matters more here than it looks: compose mounts node_modules as a NAMED
# VOLUME, and Docker seeds that volume from the image -- ownership included. So
# the wrong ownership is copied into the volume on first start and survives
# every later rebuild until the volume itself is removed.
#
# The backend Dockerfile already does exactly this before its own USER line;
# this brings the frontend in line with it.
# Source arrives as a bind mount from compose, not a COPY: the point of the
# dev target is that edits are visible without a rebuild.
RUN chown -R node:node /app

EXPOSE 3000
USER node
CMD ["npm", "run", "dev", "--", "--host", "0.0.0.0"]


FROM deps AS build
WORKDIR /app
COPY . .
ENV NODE_ENV=production
RUN npm run build


FROM node:22-alpine AS runtime
WORKDIR /app

ENV NODE_ENV=production \
    NITRO_HOST=0.0.0.0 \
    NITRO_PORT=3000

# Only the server bundle. No source, no node_modules, no dev dependencies --
# Nitro's output is self-contained, which is what makes this image small and
# its attack surface narrow.
COPY --from=build --chown=node:node /app/.output ./.output

# node:alpine ships an unprivileged `node` user; use it rather than inventing
# one. Running a network-facing process as root buys nothing.
USER node

EXPOSE 3000

# Hits the app's own route rather than shelling out to curl, which is not in
# this image and should not be added just to poll a port.
HEALTHCHECK --interval=15s --timeout=5s --start-period=20s --retries=3 \
    CMD node -e "fetch('http://127.0.0.1:3000/').then(r=>process.exit(r.ok?0:1)).catch(()=>process.exit(1))"

CMD ["node", ".output/server/index.mjs"]
