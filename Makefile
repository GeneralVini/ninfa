.PHONY: setup syntax profile-test

setup: syntax profile-test

syntax:
	find src scripts tests bin -type f \( -name '*.php' -o -path 'bin/ninfa' \) -print0 | xargs -0 -n1 php -l

profile-test:
	php -d zend.assertions=1 -d assert.exception=1 tests/project-context.php
	php -d zend.assertions=1 -d assert.exception=1 tests/external-config.php
	php -d zend.assertions=1 -d assert.exception=1 tests/pipeline-plan.php
	php -d zend.assertions=1 -d assert.exception=1 tests/semantic-hints.php
	php -d zend.assertions=1 -d assert.exception=1 tests/tooling-integration.php
	php tests/legacy-config-policy.php
	bash tests/glpi-plugin-profile.sh
