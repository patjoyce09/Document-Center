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

## Running local end-to-end checks
When binary files are present, you can run OCR extraction locally and compare results against expected metrics. The smoke test falls back to sample text fixtures when binaries are unavailable.
