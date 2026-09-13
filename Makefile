.PHONY: setup install install-force configure configure-force profile-test hooks fix qa check security security-dast lint rector stan psalm psalm-taint test audit semgrep

install:
	composer install
	php scripts/ninfa-configure.php .

install-force:
	composer install
	php scripts/ninfa-configure.php . --force

configure:
	php scripts/ninfa-configure.php .

configure-force:
	php scripts/ninfa-configure.php . --force

profile-test:
	bash tests/glpi-plugin-profile.sh

setup:
	bash scripts/bootstrap.sh

hooks:
	lefthook install

fix:
	composer fix

qa:
	composer qa

check:
	composer check

security:
	composer security

security-dast:
	composer security:dast

lint:
	composer lint

rector:
	composer rector

stan:
	composer stan

psalm:
	composer psalm

psalm-taint:
	composer psalm:taint

test:
	composer test

audit:
	composer security:dependencies

semgrep:
	composer security:semgrep
