# Deploy through recipe/default.php. The project's root makefile includes it:
#   -include project/vendor/adt/docker-deployer/make/deploy.mk
# (`-include`, a fresh checkout has no vendor/ yet). The targets expect RUN_ARGS (the arguments
# after `--`) defined by adt/make.

# Tag of the appsdevteam/deployer image for `dep`. The recipe is written for Deployer 8
# (8.0.5 = PHP 8.4); without the variable bin/dep takes latest = Deployer 6.
DEPLOYER_VERSION ?= 8.0.5
export DEPLOYER_VERSION

# Called by path, so that the deploy works without PATH from ~/.bash_aliases
DEP ?= project/vendor/bin/dep

# Who deploys - written to .dep/deploy.lock and .dep/releases_log. The Deployer container has no
# git, it would only find `root`.
DEP_USER ?= $(or $(shell git config user.name 2>/dev/null),$(shell whoami))

# Deployer 8 runs every task on all hosts of the stage concurrently (no --limit). When a task fails
# on one host, it still finishes on the others - `make deploy-unlock -- <stage>` releases the lock.
# Several stages at once: `make deploy -- main <other>`. The recipe loads the config of one stage only
# (`-o stage=`), so every stage gets its own `dep`, all running concurrently. The output is prefixed
# `[<stage>]` and saved to DEP_LOG_DIR, a summary follows and the target fails when any stage failed.
# dep runs without stdin: with a terminal on stdin bin/dep adds `docker run -t` and the concurrent
# background processes then exit 157. Options of dep are passed to every call: without a value
# directly (`make deploy -- main --plan`), with a value through DEP_ARGS
# (`make deploy -- main DEP_ARGS="-o release_name=15"`) - make takes every argument with `=` as
# a variable assignment (even after `--`), it would never reach dep.
DEP_STAGES = $(filter-out -%,$(RUN_ARGS))
DEP_OPTIONS = $(filter -%,$(RUN_ARGS)) $(DEP_ARGS)
DEP_LOG_DIR ?= log/deploy
define dep_each
	@if [ -z "$(DEP_STAGES)" ]; then echo "Chybí stage: make $@ -- <stage> [<stage> ...]" >&2; exit 1; fi
	@run() { $(DEP) $(1) -o stage=$$1 -o "user=$(DEP_USER)" $(DEP_OPTIONS) -o migrations_auto_confirm=true -vvv; }; \
	failed=; mkdir -p $(DEP_LOG_DIR); \
	for stage in $(DEP_STAGES); do \
		log=$(DEP_LOG_DIR)/$$(echo $(1) | tr : -)-$$stage.log; \
		{ run $$stage </dev/null 2>&1 && echo 0 > $$log.rc || echo $$? > $$log.rc; } \
			| awk -v p="[$$stage] " -v f=$$log '{ print p $$0; fflush(); print > f }' & \
	done; \
	wait; \
	echo "===== $(1): souhrn (logy v $(DEP_LOG_DIR)) ====="; \
	for stage in $(DEP_STAGES); do \
		log=$(DEP_LOG_DIR)/$$(echo $(1) | tr : -)-$$stage.log; rc=$$(cat $$log.rc); rm -f $$log.rc; \
		if [ "$$rc" = 0 ]; then printf '\033[32m%-6s %s\033[0m\n' OK $$stage; \
		else printf '\033[1;31m%-6s %s\033[0m\n' CHYBA "$$stage (exit $$rc, $$log)"; failed="$$failed $$stage"; fi; \
	done; \
	if [ -n "$$failed" ]; then printf '\n\033[1;31m%s NEDOKONČENO:%s\033[0m\n' "$(1)" "$$failed"; exit 1; fi
endef

# Two-step deploy (deploy:build/deploy:finish of the recipe): all stages prepare the release, then
# the pending migrations of all stages are printed and one "y" runs them everywhere. When deploy:build
# fails on any stage, nothing is asked and no migrations run. After "N" the release stays prepared and
# locked, the next `make deploy` releases the lock. Without asking: DEP_YES=1.
#
# The plan is read from the deploy:build log byte-wise (LC_ALL=C): Deployer sends the remote output in
# chunks and a multibyte character (Czech in a migration) can be split between two lines. macOS sed in
# a UTF-8 locale fails on such a line with "illegal byte sequence", the plan came out empty and the
# migrations ran without asking. Should reading the log fail anyway, the deploy stops instead of
# pretending nothing is pending.
.PHONY: dep deploy dep-pub deploy-publish deploy-unlock
dep: deploy
deploy:
	$(call dep_each,deploy:build)
	@pending=; \
	for stage in $(DEP_STAGES); do \
		plan=$$(LC_ALL=C sed -n 's/.*MIGRATIONS_PLAN> //p' $(DEP_LOG_DIR)/deploy-build-$$stage.log) \
			|| { printf '\033[1;31m%s\033[0m\n' "Plán migrací stage $$stage nejde přečíst z $(DEP_LOG_DIR)/deploy-build-$$stage.log - deploy nedokončen."; exit 1; }; \
		echo "$$plan" | LC_ALL=C grep -q MIGRATIONS_PENDING || continue; \
		pending="$$pending $$stage"; \
		printf '\n\033[1;33m===== migrace: %s =====\033[0m\n%s\n' $$stage "$$plan"; \
	done; \
	if [ -z "$$pending" ]; then echo "Žádné čekající migrace."; exit 0; fi; \
	if [ "$(DEP_YES)" = 1 ]; then exit 0; fi; \
	printf '\n\033[1;33mSpustit migrace na:%s? [y/N]\033[0m ' "$$pending"; read ans; \
	[ "$$ans" = y ] || { printf '\033[1;31m%s\033[0m\n' "Migrace nepotvrzeny - nespustily se nikde, deploy nedokončen."; exit 1; }
	$(call dep_each,deploy:finish)

# Publishes only when the deploy finished on all given stages (deploy:publish_check), otherwise nowhere.
dep-pub: deploy-publish
deploy-publish:
	$(call dep_each,deploy:publish_check)
	$(call dep_each,deploy:publish)

deploy-unlock:
	$(call dep_each,deploy:unlock)
