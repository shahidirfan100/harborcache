# Publication

## Release archive

`dist/harborcache-1.2.10.zip` is built from this checkout and verified with `python3 tools/build-release.py --verify`. The SHA256 is in `dist/SHA256SUMS`. The archive contains 600 files, excludes tests, tools, media and release notes, and carries `Version: 1.2.10` in the plugin header, the runtime constant, the drop-in header and the deployment loader.

## WordPress.org SVN

The public SVN root is `https://plugins.svn.wordpress.org/harborcache-object-cache-with-redis`. Staged copies are in `dist/wp-org-release` (plugin files) and `dist/wp-org-assets` (listing images). WordPress.org discourages changing a released tag, so 1.2.10 is a new tag rather than an edit of the published 1.2.9.

### Listing images

```powershell
$svnRoot = "https://plugins.svn.wordpress.org/harborcache-object-cache-with-redis"
svn checkout "$svnRoot/assets" hc-assets
Copy-Item dist\wp-org-assets\* hc-assets\ -Force
svn add --force hc-assets
svn status hc-assets
svn commit hc-assets -m "Update the 1.2.10 listing screenshots" --username <wordpress.org-username>
```

The nine PNGs replace the October captures. `screenshot-mobile.png` is a new file and is added by the command above. The page cache may serve the old images for a few minutes.

### Plugin release

```powershell
$svnRoot = "https://plugins.svn.wordpress.org/harborcache-object-cache-with-redis"
svn checkout "$svnRoot" hc-svn
robocopy dist\wp-org-release hc-svn\trunk /MIR /XD .svn
svn add --force hc-svn\trunk
svn status hc-svn
svn commit hc-svn\trunk -m "Release 1.2.10" --username <wordpress.org-username>
svn copy "$svnRoot/trunk" "$svnRoot/tags/1.2.10" -m "Tag 1.2.10" --username <wordpress.org-username>
```

`/MIR` mirrors the staged release, so files removed from the package are removed from `trunk`. Do not run it against a directory that still contains `.svn`; the `/XD .svn` switch keeps the working copy metadata.

### Order

1. Commit `assets` so the listing shows the new screenshots.
2. Commit `trunk`.
3. Copy `trunk` to `tags/1.2.10`.

The WordPress.org plugin page reads `Stable tag: 1.2.10` from `trunk/readme.txt`, so the listing switches to 1.2.10 after step 2.

## GitHub release

Attach `dist/harborcache-1.2.10.zip`, `dist/SHA256SUMS` and the `media/` files to the GitHub release. The ZIP is not committed to the WordPress.org assets directory.

## Evidence

- `docs/VALIDATION-1.2.10.md`: matrix result, Plugin Check output, screenshot capture run and limits.
- `docs/VALIDATION-1.2.9.md`: engine command count and benchmark that 1.2.10 inherits unchanged.
- `media/README.md` and `media/screenshots.json`: capture environment and file dimensions.
