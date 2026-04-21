#!/usr/bin/env bash
# Compare production server repo to GitHub (run on the server).
# Does not change files — report only (unless you rely on exit code).
#
# Env:
#   DEPLOY_DIR              — app root (default /www/playlist.nivessa.com/app)
#   DEPLOY_BRANCH           — branch (default main)
#   DEPLOY_GIT_REMOTE       — same as deploy.sh (origin / erp / explicit)
#   GIT_DRIFT_REPORT_ONLY   — if 1: always exit 0 (print report only)
#
# Exit code:
#   0 — safe for a fast-forward deploy (clean tree, no server-only commits, not diverged)
#   1 — drift / risk: dirty tree, commits on server not on GitHub, or history diverged
set -euo pipefail

DEPLOY_DIR="${DEPLOY_DIR:-/www/playlist.nivessa.com/app}"
DEPLOY_BRANCH="${DEPLOY_BRANCH:-main}"
REPORT_ONLY="${GIT_DRIFT_REPORT_ONLY:-0}"

cd "$DEPLOY_DIR" || {
  echo "git-drift: cannot cd to $DEPLOY_DIR"
  exit 1
}

if [ ! -d .git ]; then
  echo "git-drift: not a git repository: $DEPLOY_DIR"
  exit 1
fi

resolve_git_remote() {
  if [ -n "${DEPLOY_GIT_REMOTE:-}" ] && git remote get-url "${DEPLOY_GIT_REMOTE}" >/dev/null 2>&1; then
    echo "${DEPLOY_GIT_REMOTE}"
  elif git remote get-url origin >/dev/null 2>&1; then
    echo origin
  elif git remote get-url erp >/dev/null 2>&1; then
    echo erp
  else
    echo "git-drift: no usable remote (origin/erp). Remotes:" >&2
    git remote -v >&2
    exit 1
  fi
}

GIT_REMOTE="$(resolve_git_remote)"
REF_REMOTE="${GIT_REMOTE}/${DEPLOY_BRANCH}"

mkdir -p "$HOME/.ssh"
chmod 700 "$HOME/.ssh"
ssh-keyscan -t rsa,ecdsa,ed25519 github.com >> "$HOME/.ssh/known_hosts" 2>/dev/null || true

git fetch "$GIT_REMOTE" "$DEPLOY_BRANCH" --prune 2>&1

echo ""
echo "========== SERVER vs GITHUB (drift report) =========="
echo "Time (UTC): $(date -u)"
echo "Directory:  $DEPLOY_DIR"
echo "Remote:     $GIT_REMOTE  →  $(git remote get-url "$GIT_REMOTE")"
echo "Branch:     $DEPLOY_BRANCH"
echo "Local HEAD: $(git rev-parse --short HEAD)  $(git log -1 --oneline)"
echo "Remote ref: $(git rev-parse --short "$REF_REMOTE" 2>/dev/null || echo '(missing after fetch)')"
echo ""

# Commits only on server vs only on GitHub
read -r COUNT_SERVER COUNT_GITHUB <<<"$(git rev-list --left-right --count "HEAD...${REF_REMOTE}" 2>/dev/null || echo "0 0")"
echo "Commits on SERVER not on GitHub (would be lost on reset): ${COUNT_SERVER:-0}"
echo "Commits on GitHub not on SERVER (deploy would bring in):  ${COUNT_GITHUB:-0}"
echo ""

DIRTY="$(git status --porcelain 2>/dev/null || true)"
if [ -n "$DIRTY" ]; then
  echo "--- Uncommitted / local changes (tracked + untracked) ---"
  git status --short
  echo ""
else
  echo "Working tree: clean (no uncommitted changes in git status)"
  echo ""
fi

echo "--- Diff vs ${REF_REMOTE} (tree vs tree) ---"
if git rev-parse "$REF_REMOTE" >/dev/null 2>&1; then
  git diff --stat HEAD "${REF_REMOTE}" || true
  echo ""
  echo "--- File list (max 200 lines) ---"
  git diff --name-only HEAD "${REF_REMOTE}" 2>/dev/null | head -200
  [ "$(git diff --name-only HEAD "${REF_REMOTE}" 2>/dev/null | wc -l | tr -d ' ')" -gt 200 ] && echo "... (truncated)"
else
  echo "(cannot diff — remote ref missing)"
fi
echo ""

# Fast-forward possible?
ISSUES=0
REASONS=()

if [ -n "$DIRTY" ]; then
  ISSUES=1
  REASONS+=("Working tree is not clean — commit/stash/backup changes before treating server as equal to GitHub.")
fi

if [ "${COUNT_SERVER:-0}" -gt 0 ] 2>/dev/null; then
  ISSUES=1
  REASONS+=("Server has commit(s) not on GitHub — push or cherry-pick them into the repo, or you risk losing work on reset-style deploys.")
fi

if [ "${COUNT_GITHUB:-0}" -gt 0 ] 2>/dev/null; then
  if [ "${COUNT_SERVER:-0}" -gt 0 ] 2>/dev/null; then
    ISSUES=1
    REASONS+=("History diverged (both sides have unique commits) — merge/rebase in repo or manual reconciliation.")
  fi
fi

if git rev-parse "$REF_REMOTE" >/dev/null 2>&1; then
  if ! git merge-base --is-ancestor HEAD "$REF_REMOTE" 2>/dev/null; then
    ISSUES=1
    REASONS+=("Fast-forward from current HEAD to ${REF_REMOTE} is not possible (not a straight ancestor).")
  fi
fi

echo "========== Summary =========="
if [ "$ISSUES" -eq 0 ]; then
  echo "OK: Clean enough for a typical fast-forward deploy (still review commits you will pull)."
else
  echo "ATTENTION — issues detected (see above):"
  for r in "${REASONS[@]}"; do
    echo "  - $r"
  done
fi
echo "=============================="
echo ""

if [ "$REPORT_ONLY" = "1" ]; then
  exit 0
fi

exit "$ISSUES"
