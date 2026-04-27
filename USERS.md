# Users: Clinical Co-Pilot

## Target User

**Dr. Sarah Chen — Primary Care Physician, Internal Medicine**

A board-certified internist at a mid-size primary care practice. She sees 18–22 patients per day in 15-minute appointment slots. She uses OpenEMR as her practice's EHR system.

Her day starts at 8:45 AM with a quick scan of the day's schedule, then runs back-to-back appointments until 5 PM with a 30-minute lunch. Between patients she has 90 seconds — sometimes less — to shift mental context from the patient she just left to the one walking in.

She is not technology-averse, but she has zero tolerance for tools that require training, that slow her down, or that surface information she can't trust. She has been burned by clinical decision support tools that hallucinate drug interactions or show outdated data without any indication that it's stale. She will stop using a tool the first time it makes her look uninformed in front of a patient.

**What she does NOT need:** A tool that summarizes medical literature, explains ICD codes, or helps her write notes. She is an expert physician. She needs fast, accurate, *patient-specific* context — pulled from the record in front of her — presented in a way she can absorb in under 30 seconds.

---

## Use Cases

### UC-1: Pre-Room Briefing

**Moment:** Dr. Chen finishes with one patient, walks to the next room's door, and has 60–90 seconds before she knocks.

**What she asks:** "Brief me on this patient before I walk in."

**What the agent must do:**
- Retrieve: last visit date + reason, active conditions, current medications, any flagged alerts (lab out of range, overdue screenings), and today's appointment reason
- Synthesize: surface what's *changed* or *new* since the last visit — not a full chart dump
- Format: 4–6 bullet points, scannable in 20 seconds

**Why an agent (not a dashboard):**
A dashboard would show all this data but require Dr. Chen to read and mentally synthesize it herself. The agent does the synthesis: "Since her last visit 6 weeks ago, her HbA1c came back elevated at 7.9, her lisinopril was refilled, and she's coming in today for a cough — likely unrelated to the diabetes. Two overdue items: mammogram and flu shot." That is a different cognitive load than a screen of tabbed data.

**Source attribution requirement:** Every fact (HbA1c value, medication name, appointment reason) must cite the source record. The phrase "likely unrelated" should be flagged as agent inference, not source data.

---

### UC-2: Medication Safety Check

**Moment:** During the visit, Dr. Chen wants to prescribe a new medication and wants a quick interaction check against what the patient is currently taking.

**What she asks:** "What's she currently on? Any interactions I should know about before I add metformin?"

**What the agent must do:**
- Retrieve: active prescriptions from `prescriptions` table via FHIR `MedicationRequest` endpoint
- Check: flag known interaction risks based on the current medication list
- Hedge appropriately: if the interaction data is limited, say so explicitly rather than confidently clearing the medication

**Why an agent (not a dashboard):**
Dr. Chen can see the medication list in the chart. What she cannot do in 90 seconds is mentally cross-reference a new drug against 7 active medications. She wants to ask in natural language and get a targeted response for the specific drug she is considering.

**Boundary condition:** The agent must not make prescribing decisions. It surfaces information. It says "lisinopril + NSAIDs has a known interaction risk" — not "do not prescribe ibuprofen." The physician decides.

---

### UC-3: Lab Trend Follow-up

**Moment:** A patient's labs came back since the last visit. Dr. Chen needs to understand whether things are moving in the right direction.

**What she asks:** "How are her kidney labs trending? Are the creatinine levels getting worse?"

**What the agent must do:**
- Retrieve: last 3–5 `form_observation` records for creatinine and BUN via FHIR `Observation` endpoint, filtered to `category=laboratory`
- Trend: compare values over time and state direction (improving/stable/worsening)
- Include reference ranges: note what is normal so Dr. Chen can contextualize without needing to look it up
- Date every value: never give a number without its collection date

**Why an agent (not a dashboard):**
The lab results tab in OpenEMR shows a list of individual results. Understanding a trend requires clicking into multiple rows, noting dates, and mentally computing the direction. The agent answers the question directly: "Creatinine was 1.4 on March 3, 1.6 on March 28, 1.7 on April 15 — trending up, currently above the normal range of 0.6–1.2. BUN is stable."

**Source attribution requirement:** Every value must include date and the specific `form_observation` record it came from. The trend direction is agent inference — must be labeled as such.

---

### UC-4: Visit History Query

**Moment:** A patient presents with a complaint that may have a prior history Dr. Chen can't immediately recall.

**What she asks:** "Has she been in for chest pain before? How many times in the last two years?"

**What the agent must do:**
- Retrieve: encounter records via FHIR `Encounter` endpoint, filtered to the patient, sorted by date descending
- Search: look for encounters with chief complaint or SOAP notes mentioning the relevant symptom
- Return: count, dates, and one-line summary of each relevant visit

**Why an agent (not a dashboard):**
The encounter history in OpenEMR is a reverse-chronological list of visit dates and types. Finding all prior chest pain visits means clicking into each encounter and reading the note. The agent answers the question in one response.

**Limitation to surface:** SOAP note search is text-matching over free-text fields — the agent should acknowledge if the search may be incomplete (e.g., "I found 2 encounters with 'chest pain' in the notes; there may be additional encounters where it was coded differently").

---

## What the Agent Must Refuse

The agent is scoped to Dr. Chen's panel of patients within OpenEMR. It must refuse:

- Queries about patients Dr. Chen is not assigned to or does not have ACL access to
- Requests to modify any records (read-only)
- Questions that require clinical judgment ("Should I prescribe X?" → "I can show you what she's currently taking and flag known interactions, but the prescribing decision is yours")
- General medical knowledge questions not grounded in this patient's record ("What is the treatment for Type 2 diabetes?" → redirect to clinical resources)
- Any query that cannot be attributed to a source record in OpenEMR ("What do you think is causing her fatigue?" → if not diagnosable from the record, say so)

---

## Why This User, Not Others

Other valid users exist — ED residents, hospitalists, nurses. We chose Dr. Chen because:

1. **Defined time constraint:** 90 seconds between rooms is a harder constraint than "during rounds" or "whenever I have a minute." It forces us to optimize for speed and terseness.
2. **Predictable workflow:** Scheduled appointments with a known patient panel means we can optimize for depth on a known set of patients rather than breadth across unknown patients.
3. **Medication context:** Primary care involves managing chronic conditions and long medication lists — maximizing the value of UC-2 and UC-3.
4. **Trust bar is high but achievable:** Dr. Chen will use the tool if it's reliable. An ED resident operates in too much uncertainty; a hospitalist's rounding workflow is different enough to require separate use case design.

Future users (out of scope for Week 1): hospitalist (rounding-optimized), ED resident (high-volume intake), nurse (different permission scope).
