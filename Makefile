.PHONY: setup install install-force configure configure-force hooks fix qa check security security-dast lint rector stan psalm psalm-taint test audit semgrep

install:
	php scripts/ninfa-configure.php .
	composer install

install-force:
	php scripts/ninfa-configure.php . --force
	composer install

configure:
	php scripts/ninfa-configure.php .

configure-force:
	php scripts/ninfa-configure.php . --force

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
