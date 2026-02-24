#!/usr/bin/env bash
set -euo pipefail
npm --prefix web run build
cat > dist/README.md <<'EOF'
# dist deployment artifacts

This directory contains prebuilt frontend assets that are copied directly by cPanel deployment.

Build from repo root:

```bash
npm run build
```

Commit updated `dist/` before pushing so shared hosting does not need Node build tooling.
EOF
