# Private OCR Real-Forms Workspace (Local-Only)

This folder is for **private local OCR benchmark artifacts** only.

## Purpose
- Benchmark OCR on real filled/returned scanned forms.
- Improve widget detection, grouping, signature/date pairing, and canonical graph readiness.
- Keep public template/seed forms separate from private real-world truth data.

## Safety Rules
- Do not commit raw filled PDFs.
- Do not commit split page PDFs.
- Do not commit local reports containing sensitive details.
- Do not move public seed/template forms into this folder.

## Folder Layout
- `raw-packets/` — private incoming full packets (PDF).
- `split-pages/` — private per-page split artifacts used for page-level benchmarks.
- `manifests/` — local case manifests (`*.local.json`) and safe examples.
- `reports/` — local benchmark output artifacts.

## Runner Usage
- Local private run: pass `--manifest=private-fixtures/ocr-realforms/manifests/realforms.local.json`
- Optional report file: pass `--artifact=private-fixtures/ocr-realforms/reports/<timestamp>.json`

The local manifest shape is documented in:
- `manifests/realforms.manifest.example.json`
