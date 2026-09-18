# DevRadar developer shortcuts.
# Thin wrappers only -- no logic lives here.

.PHONY: help setup up down build test arch lint fresh

help:
	@grep -E "^[a-z-]+:.*?## .*$$" $(MAKEFILE_LIST) | awk -F ":.*?## " "{printf \"  %-10s %s\\n\", \$$1, \$$2}"

setup: ## copy env templates
	@test -f backend/.env  || cp backend/.env.example  backend/.env
	@test -f frontend/.env || cp frontend/.env.example frontend/.env
	@echo "env files ready"

build: ## build containers
	docker compose build

up: ## start the stack
	docker compose up -d

down: ## stop the stack
	docker compose down

arch: ## enforce the dependency rule
	cd backend && php bin/arch-check.php

test: arch ## run all tests
	cd backend && composer test
	cd frontend && npm test

lint: ## check code style
	cd backend && composer lint
