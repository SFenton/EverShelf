# Runtime logs

Runtime logs are written to `data/logs/`, which is part of the persistent,
writable Docker data volume. This tracked directory now contains documentation
only.

Files are generated automatically by `api/logger.php` and follow the naming pattern:

```
evershelf_YYYY-MM-DD_HH.log
```

`data/logs/` is ignored by git.

## Configuration (`.env`)

| Variable | Default | Description |
|---|---|---|
| `LOG_LEVEL` | `INFO` | Minimum log level: `DEBUG`, `INFO`, `WARN`, `ERROR` |
| `LOG_ROTATE_HOURS` | `24` | Hours per file before rotating |
| `LOG_MAX_FILES` | `14` | Maximum number of rotated files to keep |
| `LOG_DIR` | `data/logs` | Optional absolute log-directory override |

## Format

```
[2026-05-18 14:23:11] [INFO ] [rid=a1b2c3d4] [action] Message {"ctx":"value"}
```

## Remote inspection

```
GET /api/?action=get_logs&lines=100&level=WARN
```
