# Debugging

[Documentation](README.md)

## Debugging

Turn on **Debug Logging** on the settings screen. Failures then go to the dedicated
`wp-content/jotform-bridge-logs.php` file with UTC timestamps and a
`[jotform-bridge]` prefix. Its PHP header prevents direct browser access.
Use **Clean logs** on the settings screen to clear entries without changing
the logging setting. The content directory must be writable; write failures
produce a generic warning in the PHP error log:

```text
[jotform-bridge][ERROR] Jotform returned a non-2xx status. {"path":"/user","status":401}
```

Only technical metadata is logged: a path, a status, an error code, an
integration slug. Never the API key, never an authorization header, never a
submission payload. Logging is off by default.

Common situations:

| Symptom | Cause |
| --- | --- |
| Nothing renders, and you are logged in as an administrator but see no notice | The integration renders fine — check the browser console instead |
| "There is no integration with the slug …" | Typo in the slug, or the integration was renamed |
| "Schema not synced" | Press Sync Schema on that integration; check the API key and the region |
| Every API call fails on an EU account | The region is still set to Standard |
| No template declares this slug | Check the name header and file name, and that it sits directly in `jotform-bridge-templates/` |
| Submission answers 503 | The form was never synced, or the synced schema has errors — open the integration editor to see which |
| A field is missing from Auto rendering | Its Jotform type is not supported; the Schema table marks it |
