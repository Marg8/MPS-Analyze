# syntax=docker/dockerfile:1
#
# MPS Capacity Scheduler — production image
# Multi-stage build: small final image, dependencies isolated in a venv,
# runs as a non-root user, with a built-in health check.

############################################
# Stage 1 — builder: compile/install deps  #
############################################
FROM python:3.12-slim AS builder

ENV PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1 \
    PYTHONDONTWRITEBYTECODE=1

# Isolated virtual environment we can copy wholesale into the runtime stage.
RUN python -m venv /opt/venv
ENV PATH="/opt/venv/bin:$PATH"

WORKDIR /app

COPY requirements.txt .
RUN pip install --upgrade pip && \
    pip install --no-cache-dir -r requirements.txt

############################################
# Stage 2 — runtime: minimal & non-root    #
############################################
FROM python:3.12-slim AS runtime

# Streamlit / Python runtime configuration (overridable via env / .env)
ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    PATH="/opt/venv/bin:$PATH" \
    STREAMLIT_SERVER_PORT=8501 \
    STREAMLIT_SERVER_ADDRESS=0.0.0.0 \
    STREAMLIT_SERVER_HEADLESS=true \
    STREAMLIT_BROWSER_GATHER_USAGE_STATS=false \
    STREAMLIT_SERVER_FILE_WATCHER_TYPE=none

# tini = correct signal handling / zombie reaping; curl = health check probe.
RUN apt-get update && apt-get install -y --no-install-recommends \
        curl tini \
    && rm -rf /var/lib/apt/lists/*

# Dedicated unprivileged user.
RUN groupadd --system app && \
    useradd --system --gid app --create-home --home-dir /home/app app

WORKDIR /app

# Bring the pre-built dependencies over from the builder stage.
COPY --from=builder /opt/venv /opt/venv

# Application code (respects .dockerignore).
COPY --chown=app:app . .

USER app

EXPOSE 8501

# Container-native health check hitting Streamlit's built-in endpoint.
HEALTHCHECK --interval=30s --timeout=5s --start-period=25s --retries=3 \
    CMD curl -fsS "http://localhost:${STREAMLIT_SERVER_PORT}/_stcore/health" || exit 1

ENTRYPOINT ["/usr/bin/tini", "--"]
CMD ["streamlit", "run", "app.py"]
