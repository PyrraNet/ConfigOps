---
title: WordPress.org release
description: Credential-safe, gated deployment of a verified ConfigOps release to WordPress.org.
---

# WordPress.org release

ConfigOps deploys the exact built plugin payload and `.wordpress-org/` listing assets through the **WordPress.org release** GitHub Actions workflow. Manual workflow runs are hard-coded dry runs. Only publishing a stable GitHub Release can reach WordPress.org SVN.

## One-time setup

1. In the WordPress.org account settings for `pyrra`, create a dedicated SVN password. Do not reuse the main account password.
2. In the GitHub repository, create an environment named `wordpress-org`.
3. Add environment secrets named `SVN_USERNAME` and `SVN_PASSWORD`. Set `SVN_USERNAME` to `pyrra`; paste the dedicated SVN password only into the GitHub secret field.
4. Recommended: add an environment deployment-protection rule requiring manual approval.

Secrets must never be pasted into an issue, commit, terminal transcript, release note, or chat. GitHub supplies them only to the deployment job.

## Release candidate

1. Keep the plugin header, `CONFIGOPS_VERSION`, npm metadata, WordPress `Stable tag`, changelogs, and current release documentation on the same numeric version.
2. Generate real browser evidence first, then run `npm run build:wp-assets` for the directory artwork.
3. Run the complete test and release gates. `npm run build:plugin` produces the deterministic `dist/configops-<version>.zip` archive.
4. Push the release-candidate commit to `main` and wait for CI to pass.
5. From GitHub Actions, run **WordPress.org release** manually with the current version. This validates the exact payload against SVN without committing it and does not need SVN credentials.

## Publish

1. Create tag `v<version>` on the verified `main` commit.
2. Create a GitHub Release from that tag and review its notes. A draft is safe; it does not deploy.
3. Publish the release only when the `wordpress-org` environment and both secrets are ready.
4. Approve the protected environment deployment, if configured.
5. Verify the workflow attached both the deterministic ZIP and its SHA-256 file to the GitHub Release.
6. WordPress.org sends a separate release-confirmation request for the new SVN tag. Confirm it in the plugin release dashboard or email link, then verify `https://wordpress.org/plugins/configops/` shows the intended stable version and assets.

The workflow rejects prereleases, tags that do not equal the plugin version, commits outside `main`, non-reproducible archives, inconsistent metadata, missing assets, and manual attempts to perform a live deployment.
