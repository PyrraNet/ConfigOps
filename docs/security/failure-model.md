---
title: Failure model
description: How ConfigOps behaves when evidence, storage, versions, references, or compensation cannot be proven safe.
---

# Failure model

ConfigOps is designed to lose capability before it invents certainty. The host settings request must keep working even if observation fails; the resulting capture is marked incomplete and unsafe whole-capture actions disappear.

## Fail-closed matrix

| Condition | ConfigOps behavior | Operator response |
| --- | --- | --- |
| Capture storage fails | Reports an internal capture error without breaking the host save; capture becomes incomplete | Verify the actual setting, database health, and logs; do not trust the capture as complete |
| Stop summary cannot be verified | Capture stays active for safe retry or recovers to interrupted | Retry stop after storage health is restored; inspect before any undo |
| Evidence finishes after stop boundary | Capture is marked incomplete | Review for investigation only; whole-capture undo remains unavailable |
| Probable secret detected | Stores a redacted marker, not plaintext | Re-enter credentials manually if the setting must be changed back |
| Value exceeds safe shape or depth | Keeps bounded, non-restorable evidence | Use plugin-native controls or a tested backup |
| Adapter absent or version outside range | Preserves generic evidence; disables adapter-dependent undo | Verify against the exact plugin version or update the adapter contract |
| Current value changed after capture | Returns a conflict and performs no target write | Review newer work and choose the intended state manually |
| Referenced local object missing | Refuses the restore | Recreate/select a valid object, then use the native settings screen |
| Operation lock unavailable | Refuses concurrent restore or maintenance | Wait for the active operation; investigate a stale lock if it does not clear |
| A later write in session undo fails | Attempts compensation for earlier writes and records the outcome | Verify every affected setting; treat compensation failure as an incident |
| Unknown custom-table write | Stores a value-free signal only | Use the owning plugin’s tools or a database backup |
| ConfigOps is deactivated mid-capture | Closes the capture as interrupted and incomplete | Reactivate, verify site state, and start a new bounded capture |

## What ConfigOps protects

- It does not allow observation failures to fail the original WordPress settings request.
- It redacts before persistence and never treats browser intent as write authority.
- It verifies current state before undo and serializes restore operations.
- It records value-free restore outcomes before and after writes.
- It does not call incomplete evidence complete.

## What still needs operational controls

ConfigOps cannot guarantee availability, detect every possible secret name, or reverse external side effects. Production use still requires backups, least-privilege administration, database monitoring, staging for risky changes, and a recovery procedure independent of this plugin.

For concrete preflight and verification steps, see [Undo safely](/guide/undo-safely) and [Operations](/reference/operations).
