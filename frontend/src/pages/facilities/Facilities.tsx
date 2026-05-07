// Facilities — Figma "Screen 54 — Facilities". Renders the Admin → Facilities
// sub-page: a left admin rail (groups: Users & Access / Practice / Clinical /
// System), a master list of practice facilities (cards), and an Edit Facility
// form on the right with Identity / Address / Contact / Service hours /
// Capabilities sections.
//
// This is a 1:1 port of the PHP-rendered mock previously at
// /interface/super/copilot_facilities.php. Data is hardcoded demo content
// matching the Figma — Riverside Family Medicine selected, three siblings,
// "Unsaved changes" pill visible. Future work would source it from the
// /apis/copilot/admin/facilities endpoint and wire the save flow.
//
// The navy top nav is intentionally NOT rendered here; the PHP outer shell at
// /interface/main/tabs/main.php still owns it via the #maimain iframe.

import { useState } from 'react';
import type { BootContext } from '../../shared/lib/bootContext';
import styles from './Facilities.module.css';

type SidebarItem = {
  readonly label: string;
  readonly active: boolean;
};

type SidebarGroup = {
  readonly label: string;
  readonly items: readonly SidebarItem[];
};

type FacilityPillTone = 'primary' | 'good' | 'neutral';

type FacilityCard = {
  readonly id: string;
  readonly name: string;
  readonly sub: string;
  readonly pillLabel: string;
  readonly pillTone: FacilityPillTone;
};

type ServiceHour = {
  readonly day: string;
  readonly value: string;
  readonly muted: boolean;
};

type Capability = {
  readonly slug: string;
  readonly label: string;
  readonly checked: boolean;
};

const ADMIN_NAV: readonly SidebarGroup[] = [
  {
    label: 'Users & Access',
    items: [
      { label: 'Users & Groups',  active: false },
      { label: 'ACL Editor',      active: false },
      { label: 'Active Sessions', active: false },
      { label: 'Password Policy', active: false },
    ],
  },
  {
    label: 'Practice',
    items: [
      { label: 'Facilities',         active: true  },
      { label: 'Providers',          active: false },
      { label: 'Schedule Templates', active: false },
      { label: 'Pricing',            active: false },
    ],
  },
  {
    label: 'Clinical',
    items: [
      { label: 'Forms',       active: false },
      { label: 'Lists',       active: false },
      { label: 'Templates',   active: false },
      { label: 'Issue Types', active: false },
      { label: 'Layouts',     active: false },
    ],
  },
  {
    label: 'System',
    items: [
      { label: 'Audit Log', active: false },
      { label: 'Backup',    active: false },
      { label: 'Globals',   active: false },
      { label: 'Database',  active: false },
      { label: 'Modules',   active: false },
    ],
  },
];

const FACILITIES: readonly FacilityCard[] = [
  {
    id: 'rfm-main',
    name: 'Riverside Family Medicine',
    sub: 'Main · 4521 Riverside Dr, Austin TX',
    pillLabel: '⭐ Primary',
    pillTone: 'primary',
  },
  {
    id: 'rfm-east',
    name: 'RFM East — Round Rock',
    sub: 'Satellite · 188 University Blvd',
    pillLabel: 'Active',
    pillTone: 'good',
  },
  {
    id: 'rfm-tele',
    name: 'RFM Telehealth (virtual)',
    sub: 'No physical address · Tele only',
    pillLabel: 'Active',
    pillTone: 'good',
  },
  {
    id: 'rfm-cesar',
    name: 'RFM Old Cesar Chavez',
    sub: 'Closed · Lease ended 12/2024',
    pillLabel: 'Inactive',
    pillTone: 'neutral',
  },
];

const SERVICE_HOURS: readonly ServiceHour[] = [
  { day: 'Mon', value: '7:00 AM – 6:00 PM',  muted: false },
  { day: 'Tue', value: '7:00 AM – 6:00 PM',  muted: false },
  { day: 'Wed', value: '7:00 AM – 6:00 PM',  muted: false },
  { day: 'Thu', value: '7:00 AM – 6:00 PM',  muted: false },
  { day: 'Fri', value: '7:00 AM – 5:00 PM',  muted: false },
  { day: 'Sat', value: '8:00 AM – 12:00 PM', muted: false },
  { day: 'Sun', value: 'Closed',             muted: true  },
];

const CAPABILITIES: readonly Capability[] = [
  { slug: 'lab',          label: 'On-site lab',          checked: true },
  { slug: 'imaging',      label: 'On-site imaging',      checked: true },
  { slug: 'vaccinations', label: 'Vaccinations',         checked: true },
  { slug: 'procedures',   label: 'Procedures',           checked: true },
  { slug: 'telehealth',   label: 'Telehealth',           checked: true },
  { slug: 'epcs',         label: 'EPCS DEA registered',  checked: true },
  { slug: 'ada',          label: 'Wheelchair accessible', checked: true },
];

// The Figma "Type" select shows "Outpatient" — we render that as the only
// visible option here, with the underlying service/billing/both options
// preserved from the PHP version for parity with future save wiring.
const TYPE_OPTIONS: readonly { value: string; label: string }[] = [
  { value: 'outpatient', label: 'Outpatient' },
  { value: 'service',    label: 'Service location' },
  { value: 'billing',    label: 'Billing location' },
  { value: 'both',       label: 'Service + Billing' },
];

const STATE_OPTIONS: readonly string[] = ['TX', 'CA', 'NY', 'FL', 'IL'];
const COUNTRY_OPTIONS: readonly string[] = ['USA', 'CAN', 'MEX'];

const PILL_TONE_CLASS: Record<FacilityPillTone, string> = {
  primary: styles['pillPrimary'] ?? '',
  good:    styles['pillGood']    ?? '',
  neutral: styles['pillNeutral'] ?? '',
};

type FacilitiesProps = {
  readonly boot: BootContext;
};

export function Facilities(_props: FacilitiesProps): JSX.Element {
  const [selectedId, setSelectedId] = useState<string>('rfm-main');
  const [capState, setCapState] = useState<ReadonlyMap<string, boolean>>(
    () => new Map(CAPABILITIES.map((c) => [c.slug, c.checked])),
  );

  const selected = FACILITIES.find((f) => f.id === selectedId) ?? FACILITIES[0]!;

  const toggleCap = (slug: string): void => {
    setCapState((prev) => {
      const next = new Map(prev);
      next.set(slug, !(prev.get(slug) ?? false));
      return next;
    });
  };

  return (
    <>
      <div className={styles.shell}>
        <aside className={styles.sidebar}>
          <div className={styles.sidebarHeader}>ADMIN</div>
          {ADMIN_NAV.map((group) => (
            <div key={group.label} className={styles.group}>
              <div className={styles.groupLabel}>{group.label}</div>
              {group.items.map((item) => {
                const cls = item.active
                  ? `${styles.navItem} ${styles.navItemActive}`
                  : styles.navItem;
                return (
                  <a key={item.label} className={cls} href="#" target="_self">
                    {item.label}
                  </a>
                );
              })}
            </div>
          ))}
        </aside>

        <div className={styles.main}>
          <header className={styles.pagehead}>
            <div className={styles.pageheadTitle}>Facilities</div>
            <span className={styles.pageheadDot}>•</span>
            <div className={styles.pageheadMeta}>
              {FACILITIES.length} facilities · 1 selected for edit
            </div>
            <span className={styles.pageheadSpacer} />
            <button type="button" className={styles.newBtn}>+ New facility</button>
          </header>

          <div className={styles.body}>

            <div className={styles.list}>
              <div className={styles.listLabel}>PRACTICE FACILITIES</div>
              {FACILITIES.map((f) => {
                const isActive = f.id === selectedId;
                const cardCls = isActive
                  ? `${styles.card} ${styles.cardActive}`
                  : styles.card;
                const pillCls = `${styles.pill} ${PILL_TONE_CLASS[f.pillTone]}`;
                return (
                  <button
                    key={f.id}
                    type="button"
                    className={cardCls}
                    onClick={() => setSelectedId(f.id)}
                  >
                    <span className={styles.cardIcon}>🏥</span>
                    <div className={styles.cardBody}>
                      <div className={styles.cardName}>{f.name}</div>
                      <div className={styles.cardSub}>{f.sub}</div>
                      <span className={pillCls}>{f.pillLabel}</span>
                    </div>
                  </button>
                );
              })}
            </div>

            <form className={styles.form} onSubmit={(e) => e.preventDefault()}>
              <div className={styles.formHead}>
                <div className={styles.formHeadInfo}>
                  <span className={styles.formHeadKicker}>EDIT FACILITY</span>
                  <span className={styles.formHeadTitle}>{selected.name}</span>
                </div>
                <span className={styles.dirtyPill}>Unsaved changes</span>
                <span className={styles.formHeadSpacer} />
                <button type="button" className={styles.btnGhost}>Cancel</button>
                <button type="submit" className={styles.btnPrimary}>Save changes</button>
              </div>

              <div className={styles.formBody}>

                <section className={styles.section}>
                  <div className={styles.sectionLabel}>IDENTITY</div>
                  <div className={`${styles.grid} ${styles.identityR1}`}>
                    <div className={styles.field}>
                      <label className={styles.fieldLabel}>Facility name</label>
                      <input className={styles.input} type="text" defaultValue="Riverside Family Medicine" />
                    </div>
                    <div className={styles.field}>
                      <label className={styles.fieldLabel}>Short name / code</label>
                      <input className={styles.input} type="text" defaultValue="RFM-Main" />
                    </div>
                  </div>
                  <div className={`${styles.grid} ${styles.identityR2}`}>
                    <div className={styles.field}>
                      <label className={styles.fieldLabel}>NPI (organization)</label>
                      <input className={styles.input} type="text" defaultValue="1234567890" />
                    </div>
                    <div className={styles.field}>
                      <label className={styles.fieldLabel}>Type</label>
                      <select className={styles.select} defaultValue="outpatient">
                        {TYPE_OPTIONS.map((o) => (
                          <option key={o.value} value={o.value}>{o.label}</option>
                        ))}
                      </select>
                    </div>
                    <div className={styles.field}>
                      <label className={styles.fieldLabel}>Tax ID (EIN)</label>
                      <input className={styles.input} type="text" defaultValue="74-3219876" />
                    </div>
                  </div>
                </section>

                <section className={styles.section}>
                  <div className={styles.sectionLabel}>ADDRESS</div>
                  <div className={`${styles.grid} ${styles.addressR1}`}>
                    <div className={styles.field}>
                      <label className={styles.fieldLabel}>Street</label>
                      <input className={styles.input} type="text" defaultValue="4521 Riverside Drive, Suite 200" />
                    </div>
                  </div>
                  <div className={`${styles.grid} ${styles.addressR2}`}>
                    <div className={styles.field}>
                      <label className={styles.fieldLabel}>City</label>
                      <input className={styles.input} type="text" defaultValue="Austin" />
                    </div>
                    <div className={styles.field}>
                      <label className={styles.fieldLabel}>State</label>
                      <select className={styles.select} defaultValue="TX">
                        {STATE_OPTIONS.map((s) => (
                          <option key={s} value={s}>{s}</option>
                        ))}
                      </select>
                    </div>
                    <div className={styles.field}>
                      <label className={styles.fieldLabel}>ZIP</label>
                      <input className={styles.input} type="text" defaultValue="78704" />
                    </div>
                    <div className={styles.field}>
                      <label className={styles.fieldLabel}>Country</label>
                      <select className={styles.select} defaultValue="USA">
                        {COUNTRY_OPTIONS.map((c) => (
                          <option key={c} value={c}>{c}</option>
                        ))}
                      </select>
                    </div>
                  </div>
                </section>

                <section className={styles.section}>
                  <div className={styles.sectionLabel}>CONTACT</div>
                  <div className={`${styles.grid} ${styles.contactRow}`}>
                    <div className={styles.field}>
                      <label className={styles.fieldLabel}>Phone</label>
                      <input className={styles.input} type="text" defaultValue="(512) 555-0100" />
                    </div>
                    <div className={styles.field}>
                      <label className={styles.fieldLabel}>Fax</label>
                      <input className={styles.input} type="text" defaultValue="(512) 555-0101" />
                    </div>
                    <div className={styles.field}>
                      <label className={styles.fieldLabel}>Email</label>
                      <input className={styles.input} type="text" defaultValue="contact@rfm-tx.org" />
                    </div>
                  </div>
                </section>

                <section className={`${styles.section} ${styles.svcSection}`}>
                  <div className={styles.svcCol}>
                    <div className={styles.sectionLabel}>SERVICE HOURS</div>
                    <div className={styles.svcRows}>
                      {SERVICE_HOURS.map((h) => {
                        const cls = h.muted
                          ? `${styles.svcVal} ${styles.svcValMuted}`
                          : styles.svcVal;
                        return (
                          <div key={h.day} className={styles.svcRow}>
                            <span className={styles.svcDay}>{h.day}</span>
                            <input className={cls} type="text" defaultValue={h.value} />
                          </div>
                        );
                      })}
                    </div>
                  </div>
                  <div className={styles.svcCol}>
                    <div className={styles.sectionLabel}>CAPABILITIES</div>
                    <div className={styles.capList}>
                      {CAPABILITIES.map((c) => {
                        const checked = capState.get(c.slug) ?? false;
                        const boxCls = checked
                          ? `${styles.capBox} ${styles.capBoxChecked}`
                          : styles.capBox;
                        return (
                          <label key={c.slug} className={styles.capRow}>
                            <input
                              type="checkbox"
                              className={styles.capInput}
                              checked={checked}
                              onChange={() => toggleCap(c.slug)}
                            />
                            <span className={boxCls}>✓</span>
                            <span className={styles.capLabel}>{c.label}</span>
                          </label>
                        );
                      })}
                    </div>
                  </div>
                </section>

              </div>
            </form>

          </div>
        </div>
      </div>
    </>
  );
}
