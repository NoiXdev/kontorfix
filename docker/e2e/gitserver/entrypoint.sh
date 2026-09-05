#!/bin/sh
set -e

# The fixture tree is mounted read-only; the working copy and the bare repo are built here.
rm -rf /srv/work /srv/git
mkdir -p /srv/work /srv/git
cp -R /fixture/. /srv/work/

cd /srv/work
git init -q -b main
git config user.email "e2e@example.invalid"
git config user.name "E2E Fixture"
git add -A
git commit -q -m "fixture"
git tag v1.0.0

git clone -q --bare /srv/work /srv/git/demo.git

echo "gitserver: serving git://0.0.0.0/demo.git"
exec git daemon \
    --reuseaddr \
    --export-all \
    --base-path=/srv/git \
    --listen=0.0.0.0 \
    --port=9418 \
    --verbose
