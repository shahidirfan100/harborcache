# Publication

## Release archive

`dist/harborcache-1.9.0.zip` is built from this checkout and verified with `python3 tools/build-release.py --verify`. The SHA256 is `9aab315d2a7170cdf18e9c1493c5dcb99564892b0f1c743d4bcdf4f401706023`, also recorded in `dist/SHA256SUMS`. The archive contains 607 files, excluding tests, tools, media and release notes, and carries `Version: 1.9.0` in the plugin header, the runtime constant, the drop-in header and the deployment loader. `python3 tools/readiness.py` checks the packaged tree against the WordPress.org requirements and reports 46 checks passed.

## WordPress.org SVN

The public SVN root is `https://plugins.svn.wordpress.org/harborcache-object-cache-with-redis`. The plugin files are staged in `dist/wp-org-release` and the listing images in `dist/wp-org-assets`. WordPress.org discourages changing a released tag, so 1.9.0 is a new tag rather than an edit of the published 1.8.1.

### Listing images

`dist/wp-org-assets` holds the eleven files the listing accepts, under the names WordPress.org reads:

| File | Content |
| --- | --- |
| `screenshot-1.png` | Status: connection state, request metrics and lifecycle controls |
| `screenshot-2.png` | Analytics: sampled request history |
| `screenshot-3.png` | Settings: connection, limits and the saved-connection test |
| `screenshot-4.png` | Cache: groups and scoped clearing |
| `screenshot-5.png` | Diagnostics: server observability and on-demand checks |
| `screenshot-6.png` | Raw report for support |
| `screenshot-7.png` | WordPress dashboard widget |
| `banner-772x250.png`, `banner-1544x500.png` | Listing banner |
| `icon-128x128.png`, `icon-256x256.png` | Listing icon |

`screenshot-1.png` to `screenshot-7.png` match the ordered list in `readme.txt`. Screenshots are 1180 px wide or narrower, inside the 1280 px limit, and the two mobile captures in `media/` are documentation only.

```powershell
$svnRoot = "https://plugins.svn.wordpress.org/harborcache-object-cache-with-redis"
svn checkout "$svnRoot/assets" hc-assets
Copy-Item dist\wp-org-assets\* hc-assets\ -Force
svn add --force hc-assets
svn status hc-assets
svn commit hc-assets -m "Update the 1.9.0 listing screenshots" --username <wordpress.org-username>
```

### Plugin release

```powershell
$svnRoot = "https://plugins.svn.wordpress.org/harborcache-object-cache-with-redis"
svn checkout "$svnRoot" hc-svn
robocopy dist\wp-org-release hc-svn\trunk /MIR /XD .svn
svn add --force hc-svn\trunk
svn status hc-svn
svn commit hc-svn\trunk -m "Release 1.9.0" --username <wordpress.org-username>
svn copy "$svnRoot/trunk" "$svnRoot/tags/1.9.0" -m "Tag 1.9.0" --username <wordpress.org-username>
```

`/MIR` mirrors the staged release, so files removed from the package are removed from `trunk`. Do not run it against a directory that still contains `.svn`; the `/XD .svn` switch keeps the working copy metadata.

### Order

1. Commit `assets` so the listing shows the new screenshots.
2. Commit `trunk`.
3. Copy `trunk` to `tags/1.9.0`.

The WordPress.org plugin page reads `Stable tag: 1.9.0` from `trunk/readme.txt`, so the listing switches to 1.9.0 after step 2.

## GitHub release

Attach `dist/harborcache-1.9.0.zip`, `dist/SHA256SUMS` and the `media/` files to the GitHub release. The ZIP is not committed to the WordPress.org assets directory.

## Evidence

- `docs/VALIDATION-1.9.0.md`: the 14-job CI result, the paired 1.8.1 performance gate and the limits.
- `media/README.md` and `media/screenshots.json`: capture environment, file dimensions and the capture run.
