# npm runs as the numeric user and group of the caller for every target that writes into the bind-mounted
# checkout, so no root-owned file lands there. HOME points at a writable directory because npm keeps its
# cache in it. The lang targets stay with root, because they install gettext with apk.
COMPOSE_RUN_USER = $(COMPOSE_BIN) run --rm --user $(shell id -u):$(shell id -g) -e HOME=/tmp node

# =============================================================================
# TARGETS
# =============================================================================

#### Node.js & Assets

.PHONY: install build watch clean

install: .logo ## Install node dependencies.
	@$(COMPOSE_RUN_USER) npm install
	@echo -e "${FGREEN} ✔${FRESET} Node dependencies installed"

build: .logo ## Build JavaScript bundles.
	@$(COMPOSE_RUN_USER) npm run prepare
	@echo -e "${FGREEN} ✔${FRESET} JavaScript bundles built"

watch: .logo ## Watch for changes and rebuild automatically.
	@$(COMPOSE_RUN) npm run watch

clean: .logo ## Remove node_modules.
	@$(COMPOSE_RUN) rm -rf node_modules
	@echo -e "${FGREEN} ✔${FRESET} node_modules removed"
