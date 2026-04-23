.PHONY: help
help: #! Show this help message
	@echo 'Usage: make [target] ... '
	@echo ''
	@echo 'Targets:'
	@grep -h -F '#!' $(MAKEFILE_LIST) | grep -v grep | sed 's/:.*#!/:/' | column -t -s":"

.PHONY: test
test: #! Run unit tests
	composer test

.PHONY: lint
lint: #! Run quality control tools (psalm, cs-check)
	composer psalm
	composer cs-check
