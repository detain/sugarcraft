# Self-contained image for running scripts/generate_icons.py.
#
# Build:
#   docker build -t sugar-icons scripts/
#
# Run (single-GPU host):
#   docker run --rm --gpus all -v "$PWD":/repo \
#       -e HF_TOKEN="$HF_TOKEN" -e HF_HOME=/repo/.cache/huggingface \
#       sugar-icons python /repo/scripts/generate_icons.py
#
# Run (4x96GB Blackwell host):
#   docker run --rm --gpus all -v "$PWD":/repo --shm-size=8g \
#       -e HF_TOKEN="$HF_TOKEN" -e HF_HOME=/repo/.cache/huggingface \
#       sugar-icons python /repo/scripts/generate_icons.py --parallel-gpus 4
#
# HF_TOKEN is only needed for --model dev / --model krea (gated repos).
# Mounting HF_HOME inside the repo means the model cache survives container
# rebuilds. Drop that env var to use the default ~/.cache/huggingface.

FROM nvidia/cuda:12.4.1-runtime-ubuntu22.04

ENV DEBIAN_FRONTEND=noninteractive \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1

RUN apt-get update && apt-get install -y --no-install-recommends \
        python3.11 python3.11-venv python3-pip \
        git ca-certificates && \
    rm -rf /var/lib/apt/lists/* && \
    update-alternatives --install /usr/bin/python python /usr/bin/python3.11 1 && \
    update-alternatives --install /usr/bin/python3 python3 /usr/bin/python3.11 1

WORKDIR /opt/sugar-icons
COPY requirements.txt .
# torch wheels for the right CUDA; switch the index URL for nightly Blackwell builds.
RUN pip install --upgrade pip && \
    pip install --extra-index-url https://download.pytorch.org/whl/cu124 \
        -r requirements.txt

# script lives at /repo/scripts/generate_icons.py via the bind mount
WORKDIR /repo

# Run as non-root user for security (Codacy: Semgrep_dockerfile.security.missing-user)
RUN addgroup -g 1000 appgroup && adduser -u 1000 -G appgroup -s /bin/sh -D appuser
USER appuser

CMD ["python", "/repo/scripts/generate_icons.py", "--help"]
