# ELIQO Naturals static site (index / checkout / thankyou).
# Fix for the 502 Bad Gateway: the project had no process listening on a port,
# so Dokploy's proxy had no upstream to route to. nginx now serves the files.
FROM nginx:1.27-alpine

# Our server block (default.conf must stay OUT of .dockerignore so it is in
# the build context for this COPY).
RUN rm -f /etc/nginx/conf.d/default.conf
COPY default.conf /etc/nginx/conf.d/default.conf

# Ship the whole project into the web root. Any assets you keep in the folder
# (e.g. the pure-ghee-*.gif images) are deployed automatically.
COPY . /usr/share/nginx/html/

# default.conf also lands in the web root via "COPY ." above; drop it so it
# is not publicly served. (Dockerfile/.dockerignore are excluded by .dockerignore.)
RUN rm -f /usr/share/nginx/html/default.conf

# nginx listens on 80 -> set this app's container/domain port to 80 in Dokploy.
EXPOSE 80

HEALTHCHECK --interval=30s --timeout=3s --retries=3 \
  CMD wget -qO- http://127.0.0.1:80/ >/dev/null 2>&1 || exit 1