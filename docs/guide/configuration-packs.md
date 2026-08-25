---
title: Configuration Packs
description: Capture a safe desired state, inspect every destination difference, apply it, and undo it like any other ConfigOps change.
---

# Configuration Packs

A Configuration Pack is a private JSON file that describes a desired WordPress settings state. It is deliberately not a database dump: it carries no source-site before-state, autoload metadata, table name, SQL, or executable code.

The version 0.7 workflow is:

1. Complete an ordinary named Change Session on the source website.
2. Select **Save session as Pack**.
3. Review every affected setting. ConfigOps preselects only complete, safe settings owned by a tested adapter.
4. Remove values that should not travel, then enter a Pack name, description, and semantic version.
5. Download the `.configops.json` file and handle it as private configuration material.
6. On the destination website, select **Import Pack** and choose the file.
7. Inspect requirements, counts, warnings, and every current-to-desired difference. Remove a setting and refresh the preview if it should not apply.
8. Select **Apply Pack**. ConfigOps rechecks the preview baseline before its first write.
9. Verify the destination in the owning WordPress/plugin screens. The application appears as a **Pack** session in History and can use **Undo capture** while its applied values still match.

## Schema version 1

```json
{
  "format": "configops-pack",
  "schema_version": 1,
  "id": "5e1c8412-273b-4b25-8f20-4371ea2f52a1",
  "pack_version": "1.0.0",
  "name": "Agency Base",
  "description": "Safe defaults for a new client website.",
  "created_at": "2026-08-25T10:00:00+00:00",
  "created_with": "0.7.0",
  "requirements": {
    "wordpress": ">=7.0 <7.2",
    "plugins": {
      "woocommerce/woocommerce.php": ">=10.3 <10.4 || >=10.7 <10.8 || >=10.9 <11.1"
    }
  },
  "variables": {},
  "settings": [
    {
      "option": "woocommerce_enable_guest_checkout",
      "state": "present",
      "value": "yes",
      "adapter": {
        "id": "woocommerce",
        "schema_version": 1
      }
    }
  ],
  "extensions": {}
}
```

`state` is either `present`, with one JSON-compatible typed `value`, or `absent`, with no value. Adapter identity prevents a similarly named option from being treated as understood under a different contract. WordPress and plugin constraints must match on the destination before Apply becomes available.

The parser is strict: unknown top-level, setting, requirement, or adapter fields are rejected. A file is limited to 1 MiB, 250 settings, finite JSON numbers, bounded nesting and nodes, and bounded strings. Duplicate option names are rejected.

`variables` and `extensions` are required reserved objects and must be empty. Schema version 1 does not substitute variables or execute extensions. A future public-Pack trust model can add explicit semantics in a new schema without making private version 1 files executable.

## What export excludes

Export works from completed, integrity-clean Change Sessions. Repeated writes to one option collapse to the final desired state. An item is selectable only when:

- its complete new state is still decodable and restorable;
- no secret, credential, protected record, unsupported object, or size limit affected the complete option;
- a currently available tested adapter owns it under the same schema recorded in History;
- it is a full option state rather than a derived/runtime value or partial field patch.

This intentionally excludes more than ordinary local Undo. For example, ConfigOps may safely undo a non-secret field beside a redacted credential by patching only that field, but it will not export that incomplete option as a portable desired state.

## Apply Preview

Preview is read-only. It validates the file and destination, then reports:

- **Compatible** — settings accepted by the destination adapter contract;
- **Already matching** — desired state needs no write;
- **Will change** — complete current-to-desired differences;
- **Skipped** — already matching or explicitly blocked settings;
- **Potential conflict** — incompatible ownership, missing requirements, filtered reads, references, or other blockers;
- **Excluded for safety** — current or desired values that cannot be safely retained and compensated.

Warnings identify adapter-declared environment values, source-site URLs, absolute filesystem paths, email addresses, absolute URLs, and local content/media/user references. A warning is not a rewrite. Version 1 has no `{{site_url}}` or similar substitution; remove the item or deliberately configure its destination value afterward.

An applicable preview creates one opaque token bound to the current user, site, normalized Pack, and destination fingerprints. It expires after ten minutes and is consumed by the first Apply attempt. Changing the file, user, site, or any destination baseline requires a new preview.

## Apply and Undo

Apply refuses an active named Change Session. Under the site operation mutex it rebuilds the plan and compares every preview fingerprint before opening a Pack session. Each setting is checked immediately before its write and verified immediately afterward. If a later setting fails, ConfigOps attempts to restore every earlier setting in reverse order and marks the Pack session interrupted.

A successful run completes one History session with `origin_type: pack`, Pack UUID, and Pack version. Undo does not need a special Pack engine: the existing whole-session service checks that every applied value still matches, then restores the destination states observed immediately before Apply. If an operator or plugin changed one of those settings later, Undo refuses to overwrite it.

## Private-file handling

Secret exclusion does not make every remaining setting public. Site names, email addresses, URLs, plugin choices, and business configuration can still be sensitive. Store and transfer Pack files using the same access controls you use for deployment configuration. ConfigOps does not upload, catalog, sign, synchronize, or phone home with them.

For the broader trust boundary, continue with [Secrets & privacy](/security/secrets-privacy) and [Known limits](/reference/limits).
