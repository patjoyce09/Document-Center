# OCR Real-World Fixture Interface

This folder adds a lightweight real-input benchmark interface without committing heavy binaries.

## Included in repository
- `manifest.json` with case definitions and expected outcomes.
- Small representative text samples (`*_sample.txt`) that keep CI fast and deterministic.

## Optional local binary samples
You can add local-only binaries under `fixtures/ocr-realworld/local/` (not required for CI):
- scanned PDFs
- phone photos
- rotated/skewed captures
- low-contrast/noisy captures
- multi-page packets

Each case in `manifest.json` includes `optional_binary_path`.
You can also set `required_local_binary` per case:
- `false` (default): falls back to sample text if binary is absent.
- `true`: case is counted as skipped/missing-local-binary unless binary exists.

## Running local end-to-end checks
When binary files are present, the real-world benchmark smoke test replays actual binaries and records normalization/capture diagnostics. If binaries are unavailable, it falls back to sample text fixtures.

For operational local triage, run:

`php tests/ocr_local_replay_runner.php`

Optional flags:
- `--json` print compact JSON only
- `--artifact=reports/ocr-replay-summary.json` save replay summary/case diagnostics to a local JSON artifact
- `--manifest=fixtures/ocr-realworld/manifest.json` override manifest path
