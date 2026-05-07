// Header — top navy nav. Replaces the upstream OpenEMR Bootstrap navbar
// inside interface/main/tabs/main.php. Visually verified against Figma
// node 26:3 / 27:3 (top-nav is identical across screens 6, 7, 8, 9, 10).
//
// Behavior contract: the React tree owns visuals, click handling, and
// active-tab tracking; OpenEMR's existing window-level helpers continue
// to drive iframe lifecycles and patient search:
//
//   - navigateTab(url, target, afterLoad)  →  load URL into a tab
//   - activateTabByName(target, hideOthers) →  switch the visible tab
//   - viewPtFinder(message, type, data, ev) →  open patient finder
//
// These are defined in interface/main/tabs/js/{tabs,user_data}_view_model.js
// and remain in effect because main.php still ko.applyBindings(...) over
// #tabs_div / #framesDisplay / #attendantData (only the <nav> changes).

import { useCallback, useEffect, useRef, useState } from 'react';
import type { BootContext } from '../../shared/lib/bootContext';
import styles from './Header.module.css';

type NavItem = {
  readonly label: string;
  readonly target: string;        // tab target code (e.g. 'cal', 'msg', 'fin')
  readonly url: string;           // URL relative to webroot — empty for section headers
  readonly section?: boolean;     // section-label row in the More dropdown
  readonly indent?: boolean;      // indented child under a section label
};

type HeaderProps = {
  readonly boot: BootContext;
  readonly userName: string;
  readonly avatarMenuUrl: string;     // typically /interface/main/copilot_avatar_menu.php
  readonly initialActiveTarget: string;
  readonly primaryItems: readonly NavItem[];
  readonly moreItems: readonly NavItem[];
  readonly finderUrl: string;
};

declare global {
  interface Window {
    navigateTab?: (url: string, name: string, afterLoad?: () => void) => void;
    activateTabByName?: (name: string, hideOthers?: boolean) => void;
    viewPtFinder?: (message: string, type: string, data?: unknown, event?: unknown) => void;
    webroot_url?: string;
  }
}

export function Header(props: HeaderProps): JSX.Element {
  const [activeTarget, setActiveTarget] = useState<string>(props.initialActiveTarget);
  const [moreOpen, setMoreOpen] = useState<boolean>(false);
  const [avatarOpen, setAvatarOpen] = useState<boolean>(false);
  const [searchValue, setSearchValue] = useState<string>('');
  const moreRef = useRef<HTMLDivElement>(null);

  const goToTab = useCallback((item: NavItem): void => {
    if (!item.url) {
      // Items pulled from $menu_restrictions that are header-only (sub-menus
      // with children but no direct URL) get an empty url. Skip — a future
      // iteration can render them as flyouts.
      return;
    }
    setActiveTarget(item.target);
    setMoreOpen(false);
    const fullUrl = item.url.startsWith('http') || item.url.startsWith('/')
      ? item.url
      : `/${item.url}`;
    if (typeof window.navigateTab === 'function') {
      window.navigateTab(fullUrl, item.target, () => {
        window.activateTabByName?.(item.target, true);
      });
    } else {
      // Fallback: hard-navigate. Should not happen in the live shell.
      window.location.href = fullUrl;
    }
  }, []);

  // Bypass the legacy viewPtFinder (which expects a real DOM event +
  // reads from #anySearchBox). Replicate its useful behavior directly:
  // append the search term as a query param and load the finder tab.
  const openFinder = useCallback((): void => {
    const trimmed = searchValue.trim();
    const url = trimmed
      ? `${props.finderUrl}?search_any=${encodeURIComponent(trimmed)}`
      : props.finderUrl;
    if (typeof window.navigateTab === 'function') {
      window.navigateTab(url, 'fin', () => {
        window.activateTabByName?.('fin', true);
      });
      setActiveTarget('fin');
    } else {
      window.location.href = url;
    }
  }, [searchValue, props.finderUrl]);

  // Close More dropdown on outside click
  useEffect(() => {
    if (!moreOpen) {
      return;
    }
    const onDocClick = (e: MouseEvent): void => {
      if (moreRef.current && !moreRef.current.contains(e.target as Node)) {
        setMoreOpen(false);
      }
    };
    document.addEventListener('mousedown', onDocClick);
    return () => document.removeEventListener('mousedown', onDocClick);
  }, [moreOpen]);

  return (
    <>
      <header className={styles.bar} role="banner">
        <div className={styles.brand}>
          <div className={styles.brandIcon} aria-hidden="true" />
          <span className={styles.brandText}>OpenEMR</span>
        </div>

        <nav className={styles.menu} aria-label="Main">
          {props.primaryItems.map((item) => (
            <button
              key={item.target}
              type="button"
              className={
                activeTarget === item.target
                  ? `${styles.menuItem} ${styles.menuItemActive}`
                  : styles.menuItem
              }
              onClick={() => goToTab(item)}
            >
              {item.label}
            </button>
          ))}
          {props.moreItems.length > 0 && (
            <div className={styles.menuMore} ref={moreRef}>
              <button
                type="button"
                className={styles.menuItem}
                aria-haspopup="true"
                aria-expanded={moreOpen}
                onClick={() => setMoreOpen((v) => !v)}
              >
                More
              </button>
              {moreOpen && (
                <div className={styles.menuMoreList} role="menu">
                  {props.moreItems.map((item, idx) => {
                    if (item.section) {
                      return (
                        <div
                          key={`section-${idx}`}
                          className={styles.menuMoreSection}
                          aria-hidden="true"
                        >
                          {item.label}
                        </div>
                      );
                    }
                    const itemClass = item.indent
                      ? `${styles.menuMoreItem} ${styles.menuMoreItemIndent}`
                      : styles.menuMoreItem;
                    return (
                      <button
                        key={`${item.target}-${idx}`}
                        type="button"
                        role="menuitem"
                        className={itemClass}
                        onClick={() => goToTab(item)}
                      >
                        {item.label}
                      </button>
                    );
                  })}
                </div>
              )}
            </div>
          )}
        </nav>

        <div className={styles.spacer} />

        <form
          className={styles.search}
          onSubmit={(e) => {
            e.preventDefault();
            openFinder();
          }}
          role="search"
        >
          <input
            type="text"
            className={styles.searchInput}
            placeholder="Search patients…"
            aria-label="Search patients"
            value={searchValue}
            onChange={(e) => setSearchValue(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === 'Enter') {
                e.preventDefault();
                openFinder();
              }
            }}
          />
        </form>

        <button
          type="button"
          className={styles.user}
          onClick={() => setAvatarOpen((v) => !v)}
          aria-haspopup="true"
          aria-expanded={avatarOpen}
        >
          <div className={styles.userAvatar} aria-hidden="true" />
          <span className={styles.userName}>{props.userName}</span>
        </button>

        {avatarOpen && (
          <div
            className={styles.avatarPopover}
            role="dialog"
            aria-label="User menu"
          >
            <iframe
              src={props.avatarMenuUrl}
              title="User menu"
            />
          </div>
        )}
      </header>

      {avatarOpen && (
        <div
          className={styles.backdrop}
          onClick={() => setAvatarOpen(false)}
          aria-hidden="true"
        />
      )}
    </>
  );
}
