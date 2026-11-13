# Build a tarball ready to extract into nextcloud/custom_apps (no build step, no runtime dependencies)
VERSION := $(shell sed -n 's:.*<version>\(.*\)</version>.*:\1:p' appinfo/info.xml)
BUILD ?= build
TARBALL := $(BUILD)/webmail-$(VERSION).tar.gz

.PHONY: dist appstore test

dist:
	rm -rf $(BUILD)/webmail && mkdir -p $(BUILD)/webmail/l10n
	cp -r appinfo lib templates js css img $(BUILD)/webmail/
	# translations: the files Nextcloud loads (not their sources nor the script that builds them)
	cp l10n/*.js l10n/*.json $(BUILD)/webmail/l10n/
	cp README.md $(BUILD)/webmail/
	tar -czf $(TARBALL) -C $(BUILD) webmail
	@echo "$(TARBALL)"

# Nextcloud App Store: the tarball and its signature
# (make appstore APP_KEY=~/.nextcloud/certificates/webmail.key)
appstore: dist
	@test -n "$(APP_KEY)" || { echo "APP_KEY=<private key of the app certificate> is needed to sign"; exit 1; }
	openssl dgst -sha512 -sign "$(APP_KEY)" $(TARBALL) | openssl base64 -A > $(TARBALL).sig
	@echo "$(TARBALL).sig"

test:
	composer install --quiet
	vendor/bin/phpunit
