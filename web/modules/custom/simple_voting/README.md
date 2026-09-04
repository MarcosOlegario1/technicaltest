# Simple voting

A small voting system for Drupal 11. An administrator defines questions and
answer options; people vote once per question, either in the CMS or through a
hand-built REST API.

The module is split in two:

| Module | Responsibility |
| --- | --- |
| `simple_voting` | Entities, admin UI, the in-CMS voting flow, the global switch, and the shared voting logic. |
| `simple_voting_api` | The external REST endpoints. Depends on `simple_voting` and `basic_auth`. |

## Data model

Three custom content entities, no use of `node`:

- **`voting_question`** – the question. Has a human `question` text, a unique
  machine `identifier` (used by the API), a `status` (published) flag, and a
  per‑question `show_results` flag.
- **`voting_option`** – one answer. Belongs to a question and carries a `title`,
  a short `description` and an optional `image`.
- **`vote`** – one row per `(question, voter)`. A database unique key on that
  pair is what actually guarantees one vote per person per question.

Deleting a question deletes its options and votes; deleting an option deletes
its votes. An option that already has votes cannot be deleted on its own, so
recorded tallies stay meaningful.

## The voting logic

All the rules live in one service, `simple_voting.manager`
(`Drupal\simple_voting\VotingManager`), so the CMS form and the API controller
behave the same way:

- `isVotingEnabled()` / `checkVotingAccess()` – the global switch plus per
  question checks.
- `hasVoted()` / `getUserVote()` – duplicate detection.
- `recordVote()` – validates the option, applies flood control, writes the vote
  inside a transaction, and translates a unique‑key violation from a concurrent
  request into a `DuplicateVoteException`. Every outcome is logged.
- `getResults()` – one grouped SQL query, wrapped in a tag‑invalidated cache
  entry so repeated reads stay cheap. Every option is present, including those
  with zero votes.

Failures are typed exceptions under `Drupal\simple_voting\Exception\*` so
callers can map them to messages or HTTP status codes.

## Concurrency and integrity

- One vote per user per question is enforced by a **unique key** on
  `vote(question, voter)`, not only by an application check.
- `recordVote()` runs in a transaction and catches the integrity violation that
  a racing request produces, returning a clean "already voted" result.
- Result totals come from an indexed `GROUP BY` query on
  `vote(question, answer_option)` and are cached with a dedicated tag that the
  vote path invalidates.

## Observability

Everything of note is logged to the `simple_voting` channel (visible at
*Reports → Recent log messages*): votes recorded, duplicates blocked,
concurrent races, rate limiting, and storage failures.

## Permissions

| Permission | Grants |
| --- | --- |
| `administer simple voting` | Manage questions, options and the global setting; see every result. |
| `vote in simple voting` | Use the in‑CMS voting pages. |
| `access simple voting results` | See a tally even when the question hides it. |
| `access simple voting api` | Call the external API. |

## Admin interface

- **Content → Voting questions** (`/admin/content/voting-question`) – list, add,
  edit, delete. Each question has an **Options** tab to manage its answers and a
  **Results** tab with the live tally.
- **Content → Votes** (`/admin/content/vote`) – a read‑only audit list; votes
  can be removed here for moderation.
- **Configuration → Content authoring → Simple voting**
  (`/admin/config/content/simple-voting`) – the single **Enable voting**
  checkbox. When off, every voting route and every API endpoint returns 403,
  in the CMS and externally. Administration still works.

## In-CMS voting

- `/voting` – the published questions a signed‑in user can vote on.
- `/voting/{voting_question}` – shows the vote form; after voting it shows the
  tally when `show_results` is on, or just a confirmation when it is off.
  Administrators (or holders of *access simple voting results*) always see the
  tally.

## External API

Base path `/api/voting`. JSON only. Send `Accept: application/json`.
Authenticate with HTTP Basic auth (`basic_auth`) or a session cookie. Cookie
clients must also send an `X-CSRF-Token` (from `/session/token`) on the vote
request; Basic‑auth clients do not.

All endpoints require the `access simple voting api` permission and are blocked
while voting is globally disabled.

| Method & path | Purpose |
| --- | --- |
| `GET /api/voting/questions` | List the published questions. |
| `GET /api/voting/questions/{identifier}` | One question with its options. |
| `POST /api/voting/questions/{identifier}/vote` | Register a vote. Body: `{"option": <option id>}`. Requires an authenticated user. |
| `GET /api/voting/questions/{identifier}/results` | The tally, if the question shows results and the caller has voted. |

Responses are `{"data": ...}` on success and
`{"error": {"code": "...", "message": "..."}}` on failure.

Error codes: `authentication_required` (403), `question_not_found` (404),
`option_not_found` (404), `invalid_payload` (400), `voting_closed` (403),
`already_voted` (409), `invalid_option` (422), `rate_limited` (429),
`results_hidden` (403), `vote_required` (403), `vote_failed` (500).

### Example

```bash
curl -u voter:voter -H 'Accept: application/json' \
  https://my-lando-app.lndo.site/api/voting/questions

curl -u voter:voter -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -X POST https://my-lando-app.lndo.site/api/voting/questions/favorite_language/vote \
  -d '{"option": 1}'
```

A Postman collection is at `docs/postman/simple_voting.postman_collection.json`.

## Local demo data

`scripts/seed_demo_content.php` creates three sample questions, the `voter` and
`api_consumer` roles, matching demo users (`voter` / `voter`,
`api_consumer` / `api_consumer`), and a few votes:

```bash
lando drush php:script scripts/seed_demo_content.php
```

The database dump shipped with the repository already contains this data.
