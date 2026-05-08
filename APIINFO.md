# QuiltPrinter API Reference

A PHP-based print queue server that bridges client applications with thermal receipt printers. Two printer protocols are supported:

- **Epson ePOS** (e.g. TM-T88VII) — printer polls the server using the Epson `ServerDirectPrint` protocol.
- **Star CloudPRNT** (e.g. mC-Print3, TSP143IV) — printer polls the server using the Star CloudPRNT REST protocol.

There are two classes of endpoints:

1. **Client APIs** — called by your application to submit print jobs into the queue.
2. **Printer endpoint** — polled by the printer hardware itself (you do not normally call these from an application).

---

## Common Conventions

### Authentication

All client APIs require an `apikey` parameter.

- Keys are validated against the `api_keys` MySQL table.
- Keys must be at least **16 characters** and contain only `A-Z`, `a-z`, `0-9`, `-`, `_`.
- Each successful validation increments `request_count` and updates `last_used_at`.
- Inactive keys (`is_active = 0`) are rejected.

### Request Methods

All client APIs accept both **GET** and **POST** (parameters are read from `$_REQUEST`). For payloads larger than a few KB (PNGs, large markup) prefer `POST` with `application/x-www-form-urlencoded` or `multipart/form-data`.

### Printer ID

The `printer` parameter identifies which printer's queue the job is enqueued on. It is sanitized server-side to `[A-Za-z0-9_-]`. Any other characters are stripped; if the result is empty the request is rejected.

### Boolean Parameters

Boolean query parameters (`opendrawer`, `cut`) accept any value parsed by PHP's `FILTER_VALIDATE_BOOLEAN` — e.g. `true`, `1`, `yes`, `on` (true) or `false`, `0`, `no`, `off`, empty (false).

### Standard JSON Response

Client APIs always return JSON with at minimum:

```json
{
  "success": true,
  "message": "Print job queued",
  "job_id": "TXT_1715200000_4321",
  "printer": "kitchen-1",
  "queue_position": 1,
  "queue_depth": 1
}
```

On failure:

```json
{
  "success": false,
  "message": "Invalid API key"
}
```

If the queue overflowed (see below) the response also includes:

```json
{
  "queue_overflow": true,
  "discarded_job": "TXT_1715199000_1111"
}
```

### Queue Behavior

- Max queue depth: **10 pending jobs per printer** (`QUEUE_MAX_DEPTH`).
- When a new job is queued and the printer already has 10 pending jobs, the **oldest pending job is discarded** to make room (FIFO eviction).
- Job statuses cycle: `pending` → `processing` → `completed` / `failed`.

---

## Client APIs

### 1. `textapi.php` — Plain Text (Epson ePOS)

Accepts plain text with newline separation, builds an ePOS-Print XML document, and queues it for an Epson printer.

**Parameters**

| Name         | Required | Type    | Description                                                  |
|--------------|----------|---------|--------------------------------------------------------------|
| `apikey`     | yes      | string  | API key (≥16 chars).                                         |
| `printer`    | yes      | string  | Printer ID (sanitized to `[A-Za-z0-9_-]`).                   |
| `text`       | yes\*    | string  | Plain text. `\r\n`/`\r` are normalized to `\n`.              |
| `opendrawer` | no       | bool    | Pulse cash drawer 1 after printing. Default `false`.         |
| `cut`        | no       | bool    | Feed and cut paper after printing. Default `true`.           |

\* `text` may be empty if `opendrawer=true` (drawer-only request).

**Success Response Extras**

```json
{
  "line_count": 5,
  "open_drawer": false,
  "cut": true
}
```

**Example**

```bash
curl -X POST https://quiltprinter.com/textapi.php \
  --data-urlencode "apikey=YOUR_KEY_HERE_1234567890" \
  --data-urlencode "printer=front-counter" \
  --data-urlencode "text=Hello world
Receipt #42
Thank you!"
```

---

### 2. `xmlapi.php` — Raw ePOS XML (Epson)

Accepts a complete, pre-formatted `PrintRequestInfo` XML document and queues it as-is. The client is responsible for all formatting (text, cuts, drawer pulses, images, etc.).

**Parameters**

| Name      | Required | Type   | Description                                          |
|-----------|----------|--------|------------------------------------------------------|
| `apikey`  | yes      | string | API key.                                             |
| `printer` | yes      | string | Printer ID.                                          |
| `xml`     | yes      | string | Complete XML document. Must start with `<?xml` or `<PrintRequestInfo`. Must be well-formed. |

If the XML contains `<ePOSPrint><Parameter><printjobid>...</printjobid></Parameter>...`, that value is used as the job ID. Otherwise an `XML_<timestamp>_<rand>` ID is generated.

**Success Response Extras**

```json
{ "xml_size": 412 }
```

**Example**

```bash
curl -X POST https://quiltprinter.com/xmlapi.php \
  --data-urlencode "apikey=YOUR_KEY" \
  --data-urlencode "printer=front-counter" \
  --data-urlencode "xml=<?xml version=\"1.0\"?><PrintRequestInfo Version=\"2.00\">...</PrintRequestInfo>"
```

---

### 3. `pngapi.php` — PNG Image (Epson)

Accepts a base64-encoded PNG, rasterizes it to monochrome (1 bpp) using the luminosity grayscale formula and a brightness threshold, embeds it in ePOS-Print XML, and queues it. Used for Epson printers that do not natively render PNG.

**Parameters**

| Name         | Required | Type   | Description                                                                  |
|--------------|----------|--------|------------------------------------------------------------------------------|
| `apikey`     | yes      | string | API key.                                                                     |
| `printer`    | yes      | string | Printer ID.                                                                  |
| `png`        | yes\*    | string | Base64-encoded PNG. A `data:image/png;base64,` prefix is accepted and stripped. |
| `opendrawer` | no       | bool   | Pulse cash drawer 1 after printing. Default `false`.                         |

\* `png` may be omitted if `opendrawer=true`.

**Image Processing**

- Maximum print width: **576 dots** (80mm paper). Wider images are downscaled, preserving aspect ratio.
- Width is then padded to a multiple of 8 dots for byte alignment.
- Brightness threshold: `127` (0-255). Pixels with grayscale value below the threshold print as black.
- Palette images are auto-converted to true-color before sampling.
- Transparent pixels render as white.
- Output is automatically followed by a 2-line feed and a paper cut.

**Success Response Extras**

```json
{
  "has_image": true,
  "open_drawer": false,
  "image_width": 576,
  "image_height": 320,
  "debug": {
    "black_pixels": 12345,
    "white_pixels": 67890,
    "black_percentage": 15.42
  }
}
```

(`debug` is only included when `DEBUG_MODE` is enabled in `pngapi.php`.)

**Errors**

- `Invalid base64 encoding for PNG`
- `Data is not a valid PNG image` (magic bytes do not match `\x89PNG\r\n\x1a\n`)
- `Invalid image dimensions`

---

### 4. `starpngapi.php` — PNG Image (Star CloudPRNT)

Accepts a base64-encoded PNG and queues it for a Star CloudPRNT printer. Star printers natively render PNG, so no rasterization is performed — the original PNG bytes are forwarded.

**Parameters**

| Name         | Required | Type   | Description                                              |
|--------------|----------|--------|----------------------------------------------------------|
| `apikey`     | yes      | string | API key.                                                 |
| `printer`    | yes      | string | Printer ID.                                              |
| `png`        | yes\*    | string | Base64-encoded PNG. `data:` URL prefix is stripped. Whitespace within base64 is stripped. |
| `opendrawer` | no       | bool   | Open cash drawer at end-of-job (`X-Star-CashDrawer: end`). Default `false`. |

\* If `png` is omitted but `opendrawer=true`, a drawer-only job (`[STAR:DRAWER_ONLY]`) is queued.

**Limits**

- Maximum decoded PNG size: **5 MB**.
- Maximum dimensions: 10000×10000.

**Success Response Extras**

```json
{
  "format": "star_png",
  "has_image": true,
  "open_drawer": false,
  "image_width": 576,
  "image_height": 320,
  "image_size": 14728
}
```

---

### 5. `starmarkupapi.php` — Star Document Markup (Star CloudPRNT)

Accepts a Star Document Markup string and queues it for a Star CloudPRNT printer. Much faster than PNG because the printer renders text and barcodes natively.

> **Note:** The Star printer firmware itself processes markup natively (via CPUtil-style engines). This server does **not** ship CPUtil, so when a job is fetched the markup is converted to **plain text** by stripping formatting tags. Barcodes and QR codes are emitted as `[BARCODE: data]`, `[QR: data]`, and `[PDF417: data]` text placeholders. Use a CPUtil-equipped intermediary or a printer firmware that natively parses markup if you need full markup rendering.

**Parameters**

| Name         | Required | Type   | Description                                              |
|--------------|----------|--------|----------------------------------------------------------|
| `apikey`     | yes      | string | API key.                                                 |
| `printer`    | yes      | string | Printer ID.                                              |
| `markup`     | yes      | string | Star Document Markup content. Can also be sent as raw POST body when `Content-Type` is `text/plain` or `application/vnd.star.markup`. |
| `opendrawer` | no       | bool   | Open cash drawer at end-of-job. Default `false`.          |

**Limits**

- Maximum markup size: **1 MB**.

**Example markup**

```text
[align: center]
[magnify: width 2; height 2]
RECEIPT
[magnify]
[align: left]
================================
Coffee                    $4.50
Muffin                    $3.25
================================
[bold: on]
TOTAL                     $7.75
[bold: off]
[barcode: type code128; data 12345678]
[cut: feed; partial]
```

**Success Response Extras**

```json
{
  "format": "star_markup",
  "open_drawer": false,
  "markup_size": 248
}
```

---

### 6. `testprint.php` — HTML Test Page

A human-friendly endpoint that builds and queues a test receipt for an Epson printer. Returns an HTML page rather than JSON. **Not authenticated** — intended for diagnostics from a browser.

**Parameters (GET only)**

| Name         | Required | Type   | Description                          |
|--------------|----------|--------|--------------------------------------|
| `id`         | yes      | string | Printer ID.                          |
| `text`       | no       | string | Optional custom line to include.     |
| `opendrawer` | no       | bool   | Open cash drawer after printing.     |

Example: `https://quiltprinter.com/testprint.php?id=front-counter&opendrawer=true`

---

## Printer Endpoint — `index.php`

This is the URL configured on the printer itself. The server auto-detects the protocol from the request shape.

### Star CloudPRNT (when `?pid=PRINTER_ID` is present)

| Method   | Purpose                                  | Behavior                                                                                          |
|----------|------------------------------------------|---------------------------------------------------------------------------------------------------|
| `POST`   | Printer polls for a job.                 | Returns `{"jobReady": true, "mediaTypes": [...], "jobToken": "<id>"}` if a job is pending, otherwise `{"jobReady": false}`. If the body contains `{"printingInProgress": true}`, the server replies `jobReady: false` to avoid double-feeding. |
| `GET`    | Printer fetches job content for `?token=<id>`. | Returns raw print data with appropriate `Content-Type`, plus Star-specific headers like `X-Star-Cut: partial; feed=true` and `X-Star-CashDrawer: end`. Returns `404` if the token is unknown. |
| `DELETE` | Printer reports completion (`?token=<id>&code=200...`). | Marks the job complete (success if `code` starts with `200`), logs to `print_results`, returns `200`. |

**Internal job content prefixes** (set by the client APIs, parsed by `index.php`):

| Prefix on first line       | Meaning                                                                  |
|----------------------------|--------------------------------------------------------------------------|
| `[STAR:PNG]`               | Body is base64-encoded PNG. Served as `image/png`.                       |
| `[STAR:PNG:DRAWER]`        | Same as above, plus `X-Star-CashDrawer: end`.                            |
| `[STAR:MARKUP]`            | Star Document Markup, served as plain text after tag stripping.          |
| `[STAR:MARKUP:DRAWER]`     | Same as above, plus drawer pulse.                                        |
| `[STAR:DRAWER_ONLY]`       | Empty body with `Content-Length: 0`, `X-Star-Cut: none`, `X-Star-CashDrawer: start` — opens drawer without printing. |
| `[STAR:DRAWER]`            | Plain text job that should also open the drawer.                         |
| *(none)*                   | Plain text or Epson XML (text is auto-extracted from `<text>` nodes).    |

### Epson ePOS (`ServerDirectPrint`)

When `pid` is absent, the server falls back to the Epson `ServerDirectPrint` protocol. The `Content-Type` of the response is `text/xml; charset=UTF-8`.

| `ConnectionType` | Required Params               | Behavior                                                                 |
|------------------|-------------------------------|--------------------------------------------------------------------------|
| `GetRequest`     | `ID` or `Name` (printer ID)   | Returns the XML body of the next `pending` job and immediately marks it `completed`. Empty body if no jobs are pending. |
| `SetResponse`    | `ID`/`Name`, `ResponseFile`   | Parses the printer's response XML (versions `1.00` and `≥2.00` supported), logs each `<response>` to `print_results`. |

---

## Database Schema

All persistence is in MySQL/InnoDB. See [`databasesetup.sql`](databasesetup.sql) for the authoritative DDL.

### `print_queue`

| Column          | Type                                                            | Notes                                          |
|-----------------|-----------------------------------------------------------------|------------------------------------------------|
| `id`            | `BIGINT UNSIGNED AUTO_INCREMENT`                                | Primary key.                                   |
| `printer_id`    | `VARCHAR(64)`                                                   | Sanitized.                                     |
| `job_id`        | `VARCHAR(128)`                                                  | Client-visible job ID.                         |
| `content`       | `MEDIUMTEXT`                                                    | Raw payload (XML, plain text, or `[STAR:*]`).  |
| `status`        | `ENUM('pending','processing','completed','failed')`             | Default `pending`.                             |
| `created_at`    | `DATETIME(3)`                                                   | Default `CURRENT_TIMESTAMP(3)`.                |
| `processed_at`  | `DATETIME(3) NULL`                                              | Updated when status changes.                   |
| `error_message` | `VARCHAR(255) NULL`                                             | Set on failure.                                |

Indexes: `(printer_id, status, created_at)`, `(printer_id, status)`, `(status, processed_at)`, `(job_id)`.

### `api_keys`

| Column          | Type                  | Notes                                  |
|-----------------|-----------------------|----------------------------------------|
| `id`            | `INT UNSIGNED AUTO_INCREMENT` | Primary key.                   |
| `api_key`       | `VARCHAR(128)` UNIQUE | The key itself.                        |
| `name`          | `VARCHAR(255)`        | Human-readable label.                  |
| `is_active`     | `TINYINT(1)`          | `0` to revoke.                         |
| `created_at`    | `DATETIME`            |                                        |
| `last_used_at`  | `DATETIME NULL`       | Updated on successful validation.      |
| `request_count` | `BIGINT UNSIGNED`     | Incremented on successful validation.  |

Helper functions in `apiauth.php`: `validateApiKey()`, `createApiKey()`, `deactivateApiKey()`, `listApiKeys()`.

### `print_results`

Append-only log of printer responses (Epson `SetResponse` and Star `DELETE` reports).

| Column             | Type                  | Notes                                              |
|--------------------|-----------------------|----------------------------------------------------|
| `id`               | `BIGINT UNSIGNED AUTO_INCREMENT` |                                          |
| `printer_id`       | `VARCHAR(64)`         |                                                    |
| `job_id`           | `VARCHAR(128) NULL`   | If reported by printer.                            |
| `success`          | `TINYINT(1)`          | `1` on `code` starting with `200`/`success="true"`. |
| `code`             | `VARCHAR(64) NULL`    | Printer status code.                               |
| `status_flags`     | `INT UNSIGNED NULL`   | Epson status bitmap.                               |
| `response_version` | `VARCHAR(16) NULL`    | `1.00`, `2.00`, `CloudPRNT`, etc.                  |
| `raw_response`     | `TEXT NULL`           | Raw XML/code for debugging.                        |
| `created_at`       | `DATETIME`            |                                                    |

---

## HTTP Status Codes

| Code  | Meaning                                                                |
|-------|------------------------------------------------------------------------|
| `200` | Normal response (success or expected failure as JSON).                 |
| `404` | Star CloudPRNT `GET` with a token that does not match a known job.     |
| `405` | Star CloudPRNT request with an unsupported HTTP method.                |
| `500` | (testprint.php) Failed to enqueue a job.                               |

Client APIs always return `200` and convey errors via `{"success": false, "message": "..."}`.

---

## Job ID Conventions

Job IDs generated by the server have these prefixes:

| Prefix         | Source endpoint        |
|----------------|------------------------|
| `TXT_`         | `textapi.php`          |
| `XML_`         | `xmlapi.php` (when XML lacks a `printjobid`) |
| `PNG_`         | `pngapi.php`           |
| `STAR_PNG_`    | `starpngapi.php`       |
| `STAR_MARKUP_` | `starmarkupapi.php`    |
| `TEST_`        | `testprint.php`        |
| `JOB_`         | `queue.php` fallback   |

Each ID is suffixed with `<unix_timestamp>_<random_4_digit>`.

---

## Local Development

See [`README.md`](README.md) for the Docker-based dev environment. The API is served at `http://localhost:8080` and MySQL is exposed on `localhost:3307`. The schema is initialized from `databasesetup.sql` on first run.

To create a working API key for local testing, either insert a row into `api_keys` directly or call `createApiKey()` from a small admin script:

```php
<?php
require_once __DIR__ . '/apiauth.php';
print_r(createApiKey('Local Dev Key'));
```
