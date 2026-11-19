# Interactive Task Intake

The intake removes design ambiguity before code. Ask in the user's language,
one small batch at a time, and adapt follow-up questions to previous answers.

## 1. Required Opening Question

Ask this even when the request says only “use Work Orchestrator”:

> What finite set of work should the library process, and what observable
> result means that the run succeeded?

Turn the answer into a concrete input example and success example. “Files” is
not concrete enough; “every `*.ndjson` file present under these two directories
at run start, producing one committed output per canonical path” is.

## 2. Resolve Material Decisions

Ask only questions whose answers are not already established.

| Decision | Ask | Why it changes the integration |
| --- | --- | --- |
| Work-set boundary | Where does the finite set come from, and when is it frozen? | Distinguishes a finite queue from a broker/perpetual consumer |
| Identity | What stable unique key identifies one item across retries and results? | Determines `WorkId`, correlation, and deduplication |
| Payload | What minimal serializable recipe lets a worker open the real data locally? | Prevents bulk data or live resources crossing IPC |
| Ordering | Are items independent, ordered only for fairness, or separated by hard dependency stages? | Selects ordered versus grouped admission |
| Opportunistic work | Which items are independent of every open/next barrier? | Prevents fillers from violating dependencies |
| Resource ownership | Which DB connections, parsers, streams, or containers must be created inside each process? | Determines bootstrap and cleanup lifecycle |
| Subtasks | Does one item split into bounded subtasks, and are they async I/O or CPU/blocking work? | Selects no managed tasks, Concurrent Fibers, or Parallel child workers |
| Result | What value is needed per item and for the whole run? May partial success be consumed? | Determines result types and ledger handling |
| Application failure | Retry, fallback value, fail only the item, or abort the run? How many attempts? | Determines `HandlesTaskFailure` behavior |
| Process loss | Is repeating an item after an unknown failure point provably safe? | Gates crash retry and idempotency declarations |
| Cancellation | Who requests it, how quickly must work cooperate, and what cleanup must finish? | Determines propagation and grace period |
| Durable recovery | What state survives death of the entire runtime, and who starts a new run? | Keeps checkpoints/deduplication in the application |
| Observability | Which facts need root hooks versus correlated worker events? | Avoids using the event channel as a data plane |
| Limits | What CPU, RAM, descriptor, database, and IPC budgets constrain concurrency? | Determines primary/child/Fiber and payload limits |
| Integration site | Which command/service owns composition, which container/framework is present, and which tests prove it? | Locates the change and verification path |

Do not assume idempotency from read-like names, infer hard dependencies from
array order, or treat retry after an exception as equivalent to retry after a
process disappears.

## 3. Present The Integration Contract

Summarize the resolved design before implementation:

```markdown
## Work Orchestrator Integration Contract

- Goal and observable success:
- Finite input set and freeze point:
- WorkId and payload recipe:
- Admission/order/barriers:
- Primary-local resources:
- Managed subtasks and execution mode:
- Per-item and run result:
- Application-failure policy:
- Process-loss/idempotency policy:
- Cancellation and cleanup:
- Durable restart owner:
- Hooks/events required:
- Process/IPC limits:
- Composition entrypoint and verification:
- Explicit assumptions or unresolved gaps:
```

Ask the user to confirm or correct this contract. Implementation may begin only
after confirmation, unless the user explicitly authorizes proceeding with the
listed assumptions.

## 4. Reopen The Gate When Semantics Change

Return to the relevant questions when implementation evidence reveals that the
work set is not finite, a payload cannot serialize, a dependency was omitted,
a retry is unsafe, or a requested guarantee requires durable infrastructure.
Do not hide the mismatch behind a different class choice.
