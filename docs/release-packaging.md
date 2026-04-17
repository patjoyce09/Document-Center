# Release Packaging Path

## Goal

Provide a low-risk, repeatable release automation boundary for clean plugin ZIP artifacts.

## Local Build Command

Run from plugin root:

- `./scripts/build-release.sh`

## What the build script validates

1. Plugin header `Version` in `document-center-builder.php`
2. `Stable tag` in `readme.txt`
3. Matching changelog section in `readme.txt` (`= x.y.z =`)

Build fails fast on mismatches.

## Output

- `dist/document-center-builder-<version>-release.zip`

## Packaging hygiene defaults

Release archive excludes repository/development noise by default:

- `.git/`
- `.github/`
- `tests/`
- `fixtures/`
- `reports/`
- `docs/`
- `scripts/`
- `dist/`
- `*.zip`
- `.DS_Store`

The exclusion model is represented in helpers and is filterable via `dcb_release_package_exclude_patterns`.

## GitHub workflow boundary

A manual workflow is included:

- `.github/workflows/release-build.yml`

It runs the same script and uploads the generated ZIP as a build artifact.

## Notes

- This is not a full release platform.
- It intentionally keeps release automation narrow, predictable, and repo-safe.
