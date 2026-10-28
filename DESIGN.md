# DESIGN.md: MONWEB By AP (ALBIANDRA PROJECT)

## Identity & Purpose
- **Product Name:** MONWEB By AP
- **Organization:** ALBIANDRA PROJECT
- **Target Deployment:** `monweb.baznassumbawa.id`
- **Core Purpose:** Centralized web operations, Git deployment (`git pull`, `git status`, branch control), disk quota & inode inspection, PHP/CodeIgniter error log reader, and automated daily 08:00 AM reporting to WhatsApp (`081936955747`) across cPanel and hPanel websites without opening Niagahoster panels.

## Design Read & Liveliness Dials
- **Design Read:** Reading this as: Industrial Web Operations & Git Deployment Console for a multi-site administrator, in a dense technical dark language, dial `ENERGY 1 / RHYTHM 2 / MOTION 1`.
- **Dials:**
  - `ENERGY: 1` (Calm, precision-focused operational console)
  - `RHYTHM: 2` (Consistent structural grid with deliberate compositional breaks between the main site matrix, live terminal output drawer, and daily WhatsApp scheduler)
  - `MOTION: 1` (Hover states and instant focus/state transitions only; zero endless loops or decorative pulses)

## Color Palette (2 Core Neutrals + 1 Deliberate Accent + Semantic Status)
- **Canvas Background (`--bg-canvas`):** `#0B0F17` (Deep industrial slate-obsidian)
- **Surface / Panel (`--bg-surface`):** `#111827` (Elevated operational surface)
- **Elevated Control / Input (`--bg-elevated`):** `#1E293B`
- **Primary Text (`--text-primary`):** `#F8FAFC` (Contrast against `#0B0F17` = 18.33:1, against `#111827` = 16.96:1)
- **Secondary Text (`--text-secondary`):** `#CBD5E1` (Contrast against `#111827` = 11.95:1)
- **Border / Hairline (`--border-subtle`):** `#334155` (Interactive control borders use `#64748B` at 3.73:1)
- **Deliberate Accent (`--accent-emerald`):** `#10B981` (Emerald Green used deliberately for primary action triggers like "Git Pull / Deploy"; dark text `#022C22` on `#10B981` has 5.97:1 contrast)

## Typography
- **UI & Data Sans:** `'Plus Jakarta Sans'`, system-ui, -apple-system, sans-serif. Chosen for high legibility at compact operational sizes and Indonesian technical copy clarity.
- **Technical Monospace:** `'JetBrains Mono'`, monospace. Used strictly for Git commit hashes, branch names, file paths, disk byte counts, SSH ports, and raw `error_log` stack traces.

## Layout & Identity Motif
- **Identity Motif:** Precision technical coordinate headers (`MONWEB By AP // ALBIANDRA PROJECT`) with tabular-numeral (`font-variant-numeric: tabular-nums`) metric cells and a side Git execution output console that shows real stdout/stderr from `monweb-agent.php` or SSH.
- **Navigation Structure:** 4 real functional views:
  1. `Overview & Git`: Site matrix, one-click `Git Pull`, `Git Status`, Disk & Inode bar, SSL check, and Add/Edit Site modal (Agent API or SSH mode).
  2. `Error & Bug Logs`: Live reader for PHP `error_log` and CodeIgniter `application/logs`, with filter and Clear Log action.
  3. `Traffic & Uptime`: Real HTTP response latency check, uptime check history, and lightweight visitor hit counter from connected agents.
  4. `Jadwal WA 08:00 & Agent`: Daily 08:00 WITA/WIB automated report generator to `081936955747` plus one-click download of pre-configured `monweb-agent.php`.

