# Seed lock — eval-relevant DB state (locked 2026-05-02)

The eval cases in `cases.json` are pinned to the OpenEMR DB state
documented below. If a case starts failing because the agent's reply
no longer matches, the first thing to check is whether the seed has
drifted — run the queries below and compare to the expected counts /
values. If the seed has drifted, the choice is:

1. Reseed the relevant tables to match this lock (when the drift
   was accidental — e.g. a re-run wiped data), or
2. Update the affected cases to match the new state, then bump
   `_meta.version` in `cases.json` and revise this file (when the
   drift represents a deliberate change to the demo data).

Per the immutable-seed rule (`feedback_seed_data_immutable` in project
memory), once seeded data is added by UI activity it must persist —
prefer option 2 for UI-driven changes.

---

## How to verify the lock

All queries assume the local docker stack:

```bash
docker exec -i development-easy-light-mysql-1 \
    mysql -uroot -proot openemr -e '<query>'
```

For the deployed envs, run the equivalent queries against the
matching Railway DB — never from a workstation; use Railway shell.

### 1. Patient roster

```sql
SELECT pid, fname, lname, DOB FROM patient_data WHERE pid IN (1,4,5,8,17) ORDER BY pid;
```

| pid | fname | lname | DOB |
|---:|---|---|---|
| 1 | Ted | Shaw | 1947-03-11 |
| 4 | Eduardo | Perez | 1957-01-09 |
| 5 | Farrah | Rolle | 1973-10-11 |
| 8 | Nora | Cohen | 1967-06-04 |
| 17 | Jim | Moses | 1945-02-14 |

### 2. Ted's encounters (`pid=1`)

```sql
SELECT encounter, DATE(date) AS date, reason
FROM form_encounter WHERE pid=1 ORDER BY date;
```

8 rows — 5 original (2023-10-15 through 2024-10-30) plus 3 same-day
UI-test encounters on 2026-05-02 (Walk-in, Annual physical exam, test
from curl). Encounter count assertions live in
`tool-encounter-history-count` and `lookup-recent-encounter-ted`.

### 3. Ted's vitals (`pid=1`)

```sql
SELECT v.id, DATE(v.date) AS date, v.bps, v.bpd, v.BMI,
       (SELECT COUNT(*) FROM forms f
        WHERE f.formdir='vitals' AND f.form_id=v.id AND f.deleted=0) AS registered
FROM form_vitals v WHERE v.pid=1 ORDER BY v.date;
```

5 rows. **Every row must show `registered=1`** — that's the bug fix
landed by `sql/copilot_seeds/register_vitals_in_forms.sql` +
`scripts/fix-vitals-seed.sh`. Without registration the FHIR
Observation vital-signs endpoint returns empty even though the
underlying data exists.

Expected BP series (used by `lookup-vitals-ted`,
`multi-step-bp-trend`):

| date | BP | BMI |
|---|---|---|
| 2023-10-15 | 148/92 | 30.2 |
| 2024-01-22 | 142/88 | 30.9 |
| 2024-04-08 | 138/86 | 30.4 |
| 2024-07-17 | 136/84 | 30.1 |
| 2024-10-30 | 144/90 | 30.6 |

### 4. uuid_mapping registration for vitals

```sql
SELECT COUNT(*) FROM uuid_mapping WHERE `table`='form_vitals';
```

Should be **>= 75** (5 vitals rows × ~15 LOINC code paths from
`FhirObservationVitalsService::COLUMN_MAPPINGS`). If 0, run
`scripts/fix-vitals-seed.sh`. Without these rows the FHIR vitals
service silently returns empty — see the script header for the full
two-step explanation.

### 5. Ted's active conditions and Rx

```sql
SELECT title, diagnosis FROM lists WHERE pid=1 AND type='medical_problem' AND activity=1;
SELECT drug, dosage FROM prescriptions WHERE patient_id=1 AND active=1;
```

4 conditions: T2DM (E11.9), Hypertension (I10), CKD Stage 3a (N18.31),
Hyperlipidemia (E78.5). 7 active prescriptions — original 4
(Metformin 1000mg, Lisinopril 20mg, Atorvastatin 20mg, Empagliflozin
10mg) plus UI-added Lisinopril 10mg, Tramadol 50mg, Oxycodone 5mg.

### 6. Farrah (pid=5)

```sql
SELECT title FROM lists WHERE pid=5 AND type='medical_problem' AND activity=1;
SELECT title FROM lists WHERE pid=5 AND type='allergy' AND activity=1;
SELECT COUNT(*) FROM form_encounter WHERE pid=5;
```

3 conditions (Asthma moderate persistent, GERD, Hyperlipidemia), 3
allergies (Aspirin, NSAIDs, Latex), 5 encounters. Used by
`lookup-allergies-farrah`, `summary-problem-oriented-farrah`,
`multi-step-allergy-vs-rx`.

### 7. Eduardo (pid=4) — sparse-data patient

```sql
SELECT COUNT(*) FROM lists WHERE pid=4 AND activity=1;
SELECT COUNT(*) FROM prescriptions WHERE patient_id=4 AND active=1;
```

Both should be 0. Used by `edge-no-data-on-file` to confirm the
agent reports "no data" rather than fabricating.

---

## What this lock does NOT cover

- Lab results (`form_observation`) — verified ad-hoc via
  `lookup-recent-labs-ted`; not a lock target because the agent's
  rubric is "report what's there OR explain there's nothing on file"
  rather than asserting specific values.
- SOAP notes content — used only by the `summary-*` cases via free-form
  rubric; specific text isn't pinned.
- UUIDs themselves — these are generated per-environment and shouldn't
  match across envs. The lock asserts that mappings *exist*, not what
  they are.
