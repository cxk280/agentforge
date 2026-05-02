FROM python:3.11-slim

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# Cache-bust the COPY layer. Pass a unique value at build time
# (--build-arg BUILD_REV=$(git rev-parse --short HEAD)) to force a
# fresh COPY when source files change. Without this, Railway's
# Buildkit can occasionally reuse a stale COPY layer even when the
# build context differs, leading to "deploy SUCCESS" with old code.
ARG BUILD_REV=dev
ENV BUILD_REV=${BUILD_REV}

COPY . .

# Railway injects $PORT; fall back to 8400 for local runs
CMD ["sh", "-c", "uvicorn main:app --host 0.0.0.0 --port ${PORT:-8400}"]
