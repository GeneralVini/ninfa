SEMGREP_BIN ?= .tools/semgrep/bin/semgrep

.PHONY: setup environment-check syntax profile-test security-tools semgrep-rules

setup: environment-check security-tools syntax semgrep-rules profile-test

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
	php -d zend.assertions=1 -d assert.exception=1 tests/tooling-integration.php
	php -d zend.assertions=1 -d assert.exception=1 tests/pipeline-runner.php
	php tests/legacy-config-policy.php
	bash tests/glpi-plugin-profile.sh
