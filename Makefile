ACT_IMAGE ?= efrecon/act:v0.2.89
SHELL := /bin/bash
WORKDIR   ?= /work
UID_GID    := $(shell id -u):$(shell id -g)
DOCKER_GID := $(shell stat -c '%g' /var/run/docker.sock)

PLATFORM  ?= -P ubuntu-latest=ghcr.io/catthehacker/ubuntu:act-24.04

# Secrets-File in ENV format
SECRETS   ?= --secret-file .secrets

ACT_ARGS ?=

define DOCKER_RUN
docker run --rm -it \
  -u $(UID_GID) \
  --group-add $(DOCKER_GID) \
  -e HOME=/home/act \
  -e XDG_CACHE_HOME=/home/act/.cache \
  -e ACT_CACHE_DIR=/home/act/.cache/actcache \
  -v /var/run/docker.sock:/var/run/docker.sock \
  -v $(PWD):$(WORKDIR) -w $(WORKDIR) \
  -v $$HOME/.cache:/home/act/.cache \
  $(ACT_IMAGE)
endef

# ---- Targets ----
.PHONY: all ci clean

all: ci clean

ci:   ## Standard-Event "push"
	status=0; \
	output="$$($(DOCKER_RUN) $(PLATFORM) $(SECRETS) $(ACT_ARGS) 2>&1 | tee /dev/stderr; printf '\n__ACT_STATUS__=%s' "$${PIPESTATUS[0]}")"; \
	status="$${output##*__ACT_STATUS__=}"; \
	output="$${output%__ACT_STATUS__=*}"; \
	echo; \
	echo "=== 🏁 Summary ==="; \
	if [ $$status -eq 0 ]; then \
		echo "No matrix failures detected."; \
	else \
		printf '%s\n' "$$output" | awk 'tolower($$0) ~ /(failure|failed|exitcode|exit code|job failed|error: process completed)/ { line=$$0; gsub(/\033\[[0-9;]*[[:alpha:]]/, "", line); if (match(line, /\[[^]]+\]/)) { job=substr(line, RSTART + 1, RLENGTH - 2); sub(/^[^/]+\//, "", job); sub(/-[0-9]+$$/, "", job); seen[job]=1; found=1 } } END { for (job in seen) print "- " job; if (!found) exit 1 }' \
			|| echo "No act failure marker found; see log above."; \
	fi; \
	exit $$status

clean:
	docker rm -f $$(docker ps -aq --filter "name=act-") 2>/dev/null || true
