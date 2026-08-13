# WordPress.org release handoff

The `.wordpress-org/` directory contains listing artwork only. It is not part of the installable plugin ZIP.

After WordPress.org approves the plugin and assigns its slug:

1. Copy the contents of `.wordpress-org/` into the SVN repository's top-level `assets/` directory.
2. Read the release version from `configops.php`, then copy the installable plugin contents into `trunk/` and the matching tag directory, for example `tags/0.2.0/`.
3. Do not commit the release ZIP or this documentation file to SVN.
4. Confirm the final plugin URL before adding it to public metadata.

Regenerate the directory artwork with `npm run build:wp-assets` after running the real browser flows that create the source screenshots in `artifacts/`.
