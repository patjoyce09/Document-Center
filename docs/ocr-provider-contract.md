# OCR Provider Contract (HTTPS API, No SSH)

Document Center Builder integrates OCR providers over HTTPS only. SSH is not used by plugin code.

## Configuration
- `dcb_ocr_mode`: `local|remote|auto`
- `dcb_ocr_api_base_url`: HTTPS base URL
- `dcb_ocr_api_key`: API key
- `dcb_ocr_api_auth_header`: auth header name (default `X-API-Key`)
- `dcb_ocr_timeout_seconds`
- `dcb_ocr_max_file_size_mb`

## Endpoints
The plugin expects the following endpoints at the configured base URL:

- `GET /health`
- `GET /capabilities`
- `POST /extract`

## Request headers
- `Accept: application/json`
- `X-DCB-Contract-Version: dcb-ocr-v1`
- `X-DCB-Request-ID: <uuid>`
- `<auth_header_name>: <api_key>`

For `POST /extract`, `Content-Type: application/json` is used.

## Extract request body (`dcb-ocr-v1`)

```json
{
  "contract_version": "dcb-ocr-v1",
  "request_id": "<uuid>",
  "file": {
    "name": "example.pdf",
    "mime": "application/pdf",
    "content_base64": "..."
  },
  "options": {
    "include_pages": true,
    "include_warnings": true
  }
}
```

## Extract response body (`dcb-ocr-v1`)

```json
{
  "contract_version": "dcb-ocr-v1",
  "request_id": "<uuid>",
  "provider": {
    "name": "hetzner-ocr",
    "version": "2026.04"
  },
  "result": {
    "engine": "ocr-engine-name",
    "text": "...",
    "pages": [],
    "warnings": [
      {"code": "low_confidence", "message": "..."}
    ],
    "failure_reason": ""
  }
}
```

## Failure reason taxonomy
Typical `failure_reason` values:
- `remote_config_invalid`
- `remote_api_key_missing`
- `remote_auth_failed`
- `remote_http_error`
- `remote_request_failed`
- `max_file_size_exceeded`
- `empty_extraction`

## Provenance expectations
The plugin stores provenance metadata including:
- provider name/version
- request ID
- contract version
- request URL
- HTTP status
- mode/provider/timestamp

## Security rule
- No SSH in plugin code.
- No hardcoded server IPs/credentials.
- All secrets provided via settings/constants.
