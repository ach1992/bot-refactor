# Next-release source contract

Owner scope: Issue #26 comments 6433839924, 6434034586 and superseding revision3 6434233761. This document describes the development candidate after RC11. Published RC10/RC11 artifacts remain immutable. No installed tenant, actual private baseInfo.php, production database, Telegram webhook, Cron or real-money operation is changed by this source work.

## Intended behavior

- The original Increase/Decrease wallet controls remain. A negative Decrease requires an explicit locked before/after preview and separate confirmation. Customers receive the committed amount/direction/resulting balance; administrators also receive numeric target ID, before/after and tracking identity.
- Home sends a new menu and preserves invoices, status results and payment evidence. Payment references include their persisted customer's numeric ID.
- Volume selection retains actual order identity, owner, expiry and agent pricing. The attention inventory distinguishes uncertain operations from historical successful paid orders and describes the evidence available for each payment subject. It never automatically approves, refunds, retries or repairs money.
- PasarGuard uses native5.5.1 API identities and Group access, independently from Marzban Host selection. Existing Marzban physical-panel grouping remains unchanged. Retired X-UI variants stay unsupported.
- Native config/subscription presentation is managed per logical server in the administrator UI. Secrets remain per installation in private baseInfo.php; display policy and exchange rates do not belong there. Existing private values are not silently imported.
- Bot-facing copy is maintained in one topic-organized message catalog. Callback/state/SQL/provider identifiers remain code contracts and are not translations.

## Schema and upgrade boundaries

Existing RC11 installations already use the RC9-to-RC11 journal/migrations; do not rerun005, rename journals or import RC10. The exact wallet journal identity and both transfer legs remain unchanged.

Migration011_provider_identity.sql is an additive, separately shipped provider identity/snapshot journal. It is required before PasarGuard setup/use and optional for existing Marzban-only installations. A partial or incompatible011 fails closed. It records physical connection incarnation, quote-pinned Groups, remote ID/created-at/operation marker, committed create reservation and non-create mutation intent. An ambiguous provider write is held for reconciliation; it never authorizes a blind second dispatch. Purchased entitlement and later mutation observations remain distinct.

010_cleanup_manifest.json and its guarded CLI are optional operator maintenance tools, not an automatically applied DROP migration or a readiness prerequisite. The only original-table retirement candidates are admins,black_list,servers, and only exact empty original shapes without dependencies and with explicit tenant/operator retention disposition can be applied. A static source search does not prove safe deletion of installed data. All current runtime members have a necessary role; there is no fabricated file-removal quota. Known retired automatic-TRON/WeSwap paths are audited by exact hash/path, with unknown files and active schedules refused.

Frozen original V0–V9 schema, tests and release evidence remain separate and unchanged. Current baseInfo.example.php removes only the six obsolete display/rate assignments explicitly authorized in revision3; real baseInfo.php remains untouched. Source ZIPs contain neither private configuration nor SQL installer effects.

## Validation and delivery

The Master must bind final source tests, PHP8.1/8.4 CI, detached original-V9 historical gates, runtime manifest/package and fresh independent review to the exact stabilized candidate. Intermediate worker tests are not aggregate acceptance. Installed Telegram/provider/payment acceptance, production migration and deployment require their own authorized operator evidence.

Actual adapter proof: client30f8eab14b01421b0cab221a301d392673f696f8 completed all11 native probe phases against unmodified upstream PasarGuard5.5.1 commit eba70d68eaa5a89e34ffebb47c8fd5ce054d0e43 on a task-owned loopback panel. Native-generated links and links_base64 each contained one configuration; DELETE204 was followed by by-ID404. This does not certify the still-changing aggregate bot or customer data-plane traffic. See Issue #81 milestones6434115683 and6434178553 and the client contract for prerequisites and diagnostic boundaries.

Operational notes: configure an absolute PasarGuard subscription URL prefix and valid native Groups/Hosts before use. The bot does not manage User Templates,on_hold or native next-plan/reset scheduling. Lower Telegram update IDs after a long idle epoch and retained transfer cursors still require explicit reconciliation; never erase financial replay history to restore availability.
