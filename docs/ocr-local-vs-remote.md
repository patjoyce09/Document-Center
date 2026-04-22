# OCR Local vs Remote (Dev + Production)

## Purpose

Keep both OCR paths active and decoupled:

- **Local OCR**: developer benchmarking, local replay, and local fallback.
- **Remote OCR**: production-style shared OCR service over HTTPS API.

Do not hardcode remote URLs or API keys in code.

## Local OCR prerequisites

Required binaries:

- `tesseract`
- `pdftotext`
- `pdftoppm`

### macOS (Homebrew)

Install:

- `brew install tesseract poppler`

Optional additional language packs:

- `brew install tesseract-lang`

### Ubuntu/Debian

Install:

- `sudo apt-get update`
- `sudo apt-get install -y tesseract-ocr poppler-utils`

## Verify local OCR readiness

Run:

- `command -v tesseract`
- `command -v pdftotext`
- `command -v pdftoppm`
- `tesseract --version`
- `pdftotext -v`
- `pdftoppm -v`
- `tesseract --list-langs`

English readiness requires `eng` in the language list.

## Run private local replay benchmark

Use private local manifest:

- `php tests/ocr_local_replay_runner.php --manifest=private-fixtures/ocr-realforms/manifests/realforms.local.json --artifact=private-fixtures/ocr-realforms/reports/realforms_replay_summary.json --json`

The replay summary includes `local_ocr_readiness` with:

- `local_ocr_available`
- `missing_binaries`
- `scanned_pdf_extraction_expected`

## Mode distinction

- `dcb_ocr_mode=remote`: use remote OCR service path.
- `dcb_ocr_mode=local`: force local OCR binaries.
- `dcb_ocr_mode=auto`: remote first, local fallback when applicable.

Remote OCR remains the production/shared service path. Local OCR is for dev benchmarking and fallback support.