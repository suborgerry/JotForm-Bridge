# Storage and uninstalling

[Documentation](README.md)

## Storage and synchronization

| Data | Where | Expires | Written by |
| --- | --- | --- | --- |
| Normalized schema (one per form) | option `jotform_bridge_schema_{id}` | never | **Sync Schema**, or **Connect form** when absent |
| Connected form records (one per form an integration names) | option `jotform_bridge_connected_forms` | never | **Connect form** |
| Integrations | option `jotform_bridge_integrations` | never | the integration editor |
| Settings | option `jotform_bridge_settings` | never | the settings screen |
| Circuit-breaker state | option `jotform_bridge_quota` | daily counts, 30 days | every accepted submission |
| Rate-limit and anti-replay buckets | transients | minutes to hours | every submission |

The template list is deliberately absent: it is not stored at all. Only the
header of each file in `jotform-bridge-templates/` is read, on demand and once per request, which
is cheap enough not to need a cache — and a cache is exactly what used to let an
edited template keep reporting the fields it declared yesterday.

Form records and schemas never expire or refresh themselves. Defensive counters
and transients have the lifetimes listed above. A front-end
request — rendering a form or accepting a submission — reads what is stored and
contacts Jotform for exactly one thing: sending an accepted submission. It never
fetches a schema, not even when none is stored; a page with no form on it does
not touch Jotform, the filesystem or the registry at all.

That is a deliberate trade. The site owner decides when a form definition
changes, and an unreachable or slow Jotform API can never appear inside a page
view a visitor is waiting on. The cost is that a form edited in Jotform keeps
rendering the old definition until somebody presses **Sync Schema**.

If a sync fails, the previously stored schema is kept and the error is shown on
the integration screen: a Jotform outage does not take your forms down.

Deactivating the plugin changes nothing at all: there is no derived state left
to drop, so reactivating leaves every form exactly as it was. A plugin upgrade
keeps the schemas too, and marks them as *synced by an older plugin version* so
you can re-sync at a moment you choose. Uninstalling removes both.

## Uninstalling

Deactivating changes nothing: the plugin keeps no derived state that outlives a
request, so everything — synced schemas included — is exactly as you left it
when you activate it again.

Deleting the plugin removes the synced schemas and the connected form records —
but **not**
the integrations or the settings, so the usual "deactivate, delete, reinstall"
round trip does not destroy work somebody did by hand. For a full removal, tick *Delete the
integrations and the settings when the plugin is deleted* on the settings screen
before deleting. The API key is not involved either way: it lives in
`wp-config.php`, which is yours to edit.
