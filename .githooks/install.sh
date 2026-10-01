#!/bin/sh
# Installs .githooks/pre-push into this clone's git hooks dir (shared by all
# worktrees). Coexists with `openemr-cmd prek-install`, which manages only
# pre-commit and commit-msg. Refuses to overwrite a foreign pre-push hook.
set -eu
root=$(git rev-parse --show-toplevel)
hooks=$(cd "$root" && git rev-parse --git-common-dir)/hooks
case "$hooks" in /*) ;; *) hooks="$root/$hooks" ;; esac
target="$hooks/pre-push"
if [ -e "$target" ] && ! grep -q __copilot_eval_gate_pre_push__ "$target"; then
    echo "Refusing to overwrite existing non-managed $target" >&2
    exit 1
fi
cp "$root/.githooks/pre-push" "$target"
chmod +x "$target" "$root/.githooks/pre-push"
echo "Installed $target"
