# V4 inherited behavior preserved rather than repaired

These findings are source-derived observations of integrated V3, not
new fixes or endorsements. The acceptance contract requires preserving
observable admin/customer output, SQL and persisted state.

| Inherited behavior | Actual consequence / handling |
|---|---|
| Inconsistent primary-owner identity | Some admin paths compare `from_id == admin`, while grants use `===`. Numeric strings can behave differently; old predicates intentionally stay in place. |
| Agency decline supplies an ignored third argument | Legacy `setUser(-1,'is_agent',$match[1])` passes a third argument to a function that accepts only two. It updates the *current actor*, not the intended applicant. Native synthetic DB tests explicitly preserve this mismatch rather than claiming it is repaired. |
| Agency application uses existing `users.is_agent` states | 0/1/2/-1 request, approve, decline and release transitions stay byte-exact, including reuse of old caller snapshots. |
| Existing callback identifiers persist | `wizwizch`, `agencyApprove*`, `agencyDecline*`, `editAgentDiscount*`, `delAdmin*`, etc. are compatibility contracts for already-sent Telegram keyboards. |
| Discount JSON and pagination are historical | `users.discount_percent` server/plan/normal values, JSON flags, loose comparisons, SQL binding types and callback generation stay untouched. |
| Ban/unban shares `users.step` with wizards | Banning sets `banned`, unban sets `none` with original input checks, notifications and old ordering. |
| Existing warnings and shared variables are observable | Malformed/empty row warnings, `match` reuse, request-start `userInfo` snapshot and fall-through are preserved. |
| Authorization guards have not been harmonized | Moving conditions to an ideal RBAC service could change live behavior. Security fixes need separate evidence, owner policy decision and targeted follow-up. |
| Notifications can precede persistent writes | No outbox, retry or cross-provider exactly-once guarantee was added in the refactor. |

V0-R01..R15 remain inherited risks. In particular historical V0-R12
publication concerns require private owner handling where applicable.
Do not reproduce possible secrets or live user data in public reports.
