FROM python:3.12-slim-bookworm

ARG VCS_REF
LABEL org.opencontainers.image.revision="${VCS_REF}"
RUN printf '%s' "${VCS_REF}" \
      | grep -Eq '^[0-9a-f]{40}([0-9a-f]{24})?$' \
    || { echo "VCS_REF must be an exact 40- or 64-character commit SHA" >&2; exit 1; }

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir --requirement requirements.txt \
    && addgroup --system --gid 10001 bridge \
    && adduser --system --uid 10001 --ingroup bridge --home /app bridge \
    && chown bridge:bridge /app

COPY --chown=bridge:bridge app.py .
RUN printf '%s\n' "${VCS_REF}" > /app/.build-revision \
    && chown bridge:bridge /app/.build-revision \
    && mkdir -p /data \
    && chown bridge:bridge /data \
    && chmod 0700 /data

USER bridge

EXPOSE 8081

HEALTHCHECK --interval=30s --timeout=5s --retries=3 \
    CMD ["python", "-c", "import urllib.request; urllib.request.urlopen('http://127.0.0.1:8081/health', timeout=3).read()"]

CMD ["python", "app.py"]
