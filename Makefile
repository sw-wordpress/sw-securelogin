.DEFAULT_GOAL := dist

.PHONY: release dist
# Updates version, commits and atomically pushes branch + tag; triggers protected signing CI.
release: export SWSL_RELEASE_VERSION = $(VERSION)
release:
	bash bin/release.sh

# Local installable ZIP of the current checkout; never publishes.
dist:
	composer build:release
