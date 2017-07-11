#!/bin/bash


set -ex


TERMINUS_SITE=build-tools-$CIRCLE_BUILD_NUM

SOURCE_COMPOSER_PROJECT=pantheon-systems/example-drops-8-composer:dev-test-2.x
TARGET_REPO: $GITHUB_USERNAME/$TERMINUS_SITE
TARGET_REPO_WORKING_COPY=$HOME/$TERMINUS_SITE
BUILD_TOOLS_VERSION=${PR_BRANCH:-$CIRCLE_BRANCH}



- terminus build:project:create -n "$SOURCE_COMPOSER_PROJECT" "$TERMINUS_SITE" --team="$TERMINUS_ORG" --email="$GIT_EMAIL" --env="BUILD_TOOLS_VERSION=$BUILD_TOOLS_VERSION"
# Confirm that the Pantheon site was created
- terminus site:info "$TERMINUS_SITE"
# Confirm that the Github project was created
- git clone "https://github.com/${TARGET_REPO}.git" "$TARGET_REPO_WORKING_COPY"
# Confirm that Circle was configured for testing, and that the first test passed.
# This process is slow and fragile, so we will not bother to re-run on the master branch.

cd "$TARGET_REPO_WORKING_COPY"





# DON'T UNCOMMENT THIS WITHOUT ALTER THE LINE AT THE TOP OF THE FILE THAT
# CAUSE ALL OUTPUT TO BE PRINTED. CONSIDER MOVING circle token OUTSIDE THIS FILE
# circle token "$CIRCLE_TOKEN"
# circle watch
