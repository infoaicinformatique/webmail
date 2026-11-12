# Build a tarball ready to extract into nextcloud/custom_apps (no build step, no runtime dependencies)
VERSION := $(shell sed -n 's:.*<version>\(.*\)</version>.*:\1:p' appinfo/info.xml)

.PHONY: dist test

dist:
	rm -rf build && mkdir -p build/webmail
	cp -r appinfo lib templates js css img build/webmail/
	cp README.md build/webmail/
	tar -czf build/webmail-$(VERSION).tar.gz -C build webmail
	@echo "build/webmail-$(VERSION).tar.gz"

test:
	composer install --quiet
	vendor/bin/phpunit
