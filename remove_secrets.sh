#!/bin/bash
# Script to remove Stripe secrets from git history

# Backup current branch
git branch backup-before-filter

# Remove secrets from config.php in commit 0521098 and all subsequent commits
git filter-branch --force --index-filter \
  "git checkout HEAD -- backend/config/config.php && \
   sed -i 's/?: '\''sk_test_[^'\'']*'\''/?: '\'''\''/g' backend/config/config.php && \
   sed -i 's/?: '\''pk_test_[^'\'']*'\''/?: '\'''\''/g' backend/config/config.php && \
   sed -i 's/?: '\''whsec_[^'\'']*'\''/?: '\'''\''/g' backend/config/config.php && \
   git add backend/config/config.php" \
  --tag-name-filter cat -- --all

echo "Secrets removed from history. Review changes and force push if satisfied."

