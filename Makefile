SEMGREP_BIN ?= .tools/semgrep/bin/semgrep
GO_DIR ?= go
GO_DEVTOOLS_DIR ?= $(GO_DIR)/devtools
GO_BUILD_DIR ?= build
GO_SECURITY_DIR ?= $(GO_BUILD_DIR)/security
GO_TOOL_BIN ?= $(abspath .tools/go/bin)
GO_TOOLS_STAMP ?= .tools/go/.installed
NINFA_GO_VERSION ?= dev
NINFA_GO_COMMIT ?= $(shell git rev-parse --short HEAD 2>/dev/null || printf unknown)

.PHONY: setup check environment-check syntax profile-test security-tools semgrep-rules go-tools go-fmt go-fmt-check go-vet go-lint go-sast go-vuln go-security go-security-report go-test go-build go-check

setup: environment-check security-tools syntax semgrep-rules profile-test

check: syntax profile-test semgrep-rules go-check

environment-check:
	bash scripts/check-environment.sh

security-tools:
	bash scripts/install-security-tools.sh

semgrep-rules: security-tools
	@test -x "$(SEMGREP_BIN)" || { printf '[ERRO] Semgrep gerenciado não encontrado: %s\n' "$(SEMGREP_BIN)" >&2; exit 1; }
	$(SEMGREP_BIN) --validate --config security/semgrep --metrics=off
	$(SEMGREP_BIN) --test --config security/semgrep --metrics=off security/semgrep-tests

syntax:
	find src scripts tests bin -type f \( -name '*.php' -o -path 'bin/ninfa' \) -print0 | xargs -0 -n1 php -l

profile-test:
	php -d zend.assertions=1 -d assert.exception=1 tests/project-context.php
	php -d zend.assertions=1 -d assert.exception=1 tests/result-contracts.php
	php -d zend.assertions=1 -d assert.exception=1 tests/security-contract.php
	php -d zend.assertions=1 -d assert.exception=1 tests/intelligence.php
	php -d zend.assertions=1 -d assert.exception=1 tests/internal-docs.php
	php -d zend.assertions=1 -d assert.exception=1 tests/shell-docs.php
	php -d zend.assertions=1 -d assert.exception=1 tests/security-inventory.php
	php -d zend.assertions=1 -d assert.exception=1 tests/composer-audit.php
	php -d zend.assertions=1 -d assert.exception=1 tests/osv.php
	php -d zend.assertions=1 -d assert.exception=1 tests/sast-parsers.php
	php -d zend.assertions=1 -d assert.exception=1 tests/external-config.php
	php -d zend.assertions=1 -d assert.exception=1 tests/pipeline-plan.php
	php -d zend.assertions=1 -d assert.exception=1 tests/semantic-hints.php
	php -d zend.assertions=1 -d assert.exception=1 tests/yii2-semantic-model.php
	php -d zend.assertions=1 -d assert.exception=1 tests/yii2-behavior-inheritance.php
	php -d zend.assertions=1 -d assert.exception=1 tests/yii2-view-resolution.php
	php -d zend.assertions=1 -d assert.exception=1 tests/yii2-query-existence.php
	php -d zend.assertions=1 -d assert.exception=1 tests/yii2-query-condition.php
	php -d zend.assertions=1 -d assert.exception=1 tests/yii2-model-rules.php
	php -d zend.assertions=1 -d assert.exception=1 tests/yii2-model-metadata.php
	php -d zend.assertions=1 -d assert.exception=1 tests/yii2-where-equality.php
	php -d zend.assertions=1 -d assert.exception=1 tests/yii2-deprecation-remediation.php
	php -d zend.assertions=1 -d assert.exception=1 tests/yii2-typed-deprecation.php
	php -d zend.assertions=1 -d assert.exception=1 tests/yii2-classname-deprecation.php
	php -d zend.assertions=1 -d assert.exception=1 tests/yii2-find-shortcut.php
	php -d zend.assertions=1 -d assert.exception=1 tests/yii2-magic-property.php
	php -d zend.assertions=1 -d assert.exception=1 tests/yii2-controller-access.php
	php -d zend.assertions=1 -d assert.exception=1 tests/tooling-integration.php
	php -d zend.assertions=1 -d assert.exception=1 tests/pipeline-runner.php
	php tests/legacy-config-policy.php
	bash tests/glpi-plugin-profile.sh

$(GO_TOOLS_STAMP): $(GO_DEVTOOLS_DIR)/go.mod
	mkdir -p "$(GO_TOOL_BIN)"
	cd "$(GO_DEVTOOLS_DIR)" && GOBIN="$(GO_TOOL_BIN)" go install tool
	mkdir -p "$(dir $(GO_TOOLS_STAMP))"
	touch "$(GO_TOOLS_STAMP)"

go-tools: $(GO_TOOLS_STAMP)

go-fmt:
	cd "$(GO_DIR)" && gofmt -w .

go-fmt-check:
	@files="$$(cd "$(GO_DIR)" && gofmt -l .)"; \
	if [ -n "$$files" ]; then \
		printf '[ERRO] Arquivos Go fora do gofmt:\n%s\n' "$$files" >&2; \
		exit 1; \
	fi

go-vet:
	cd "$(GO_DIR)" && go vet ./...

go-lint: go-tools
	cd "$(GO_DIR)" && "$(GO_TOOL_BIN)/staticcheck" ./...

go-sast: go-tools
	cd "$(GO_DIR)" && "$(GO_TOOL_BIN)/gosec" ./...

go-vuln: go-tools
	cd "$(GO_DIR)" && "$(GO_TOOL_BIN)/govulncheck" ./...

go-security: go-sast go-vuln

go-security-report: go-tools
	mkdir -p "$(GO_SECURITY_DIR)"
	cd "$(GO_DIR)" && "$(GO_TOOL_BIN)/gosec" -fmt=sarif -out="../$(GO_SECURITY_DIR)/gosec.sarif" ./...

go-test:
	cd "$(GO_DIR)" && go test ./...

go-build:
	mkdir -p "$(GO_BUILD_DIR)"
	cd "$(GO_DIR)" && go build -trimpath -buildvcs=false \
		-ldflags "-X github.com/GeneralVini/ninfa/go/internal/version.Version=$(NINFA_GO_VERSION) -X github.com/GeneralVini/ninfa/go/internal/version.Commit=$(NINFA_GO_COMMIT)" \
		-o "../$(GO_BUILD_DIR)/ninfa-go" ./cmd/ninfa-go

go-check: go-fmt-check go-vet go-lint go-sast go-vuln go-test go-build
