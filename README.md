<br />

<div align="center"><strong>observatory: NICE TAGLINE???</strong></div>

<img src="images/header.png"/>
<div align="center"><strong>PostHog analytics, native to the Craft CMS control panel.</strong></div>
<div align="center">Stop switching tabs. Observatory pulls your PostHog data into a dedicated control panel section and a set of dashboard widgets.</div>

<br />
<div align="center">
  <sub>Made possible by</sub>
  <sub><br />
  <a href="https://www.szenario-design.com/" target="_blank">
    <img src="images/szenario-logo.svg" style="width:140px;" alt="szenario-design.com logo" /></a>
  </sub><br /><br />
  <sub>The team behind the magic</sub><br />
  <sub><a href="https://twitter.com/smonist">Simon Wesp</a></sub>
  <sub><a href="https://twitter.com/thomasbendl">Thomas Bendl</a></sub>
  <sub>Erich Bendl</sub>
</div>

<br />

*This plugin is free via the Craft Plugin Store.*

<img src="images/widgets.png"/>

## Features ✨

- Dedicated observatory section in the control panel with chart, KPIs and breakdowns
- Nine dashboard widgets covering the most common needs.
- Automatic background sync via the Craft queue, plus CLI commands for manual pulls

## Requirements 📋

- Craft CMS `5.0.0+`
- PHP `8.2+`
- A PostHog project and a personal API key with read access
- A running Craft queue runner for background syncing (CRON or daemon)

## Installation 📦

**Plugin Store:** search for "observatory" in the Craft Plugin Store and press Install.

**Composer:**

```bash
composer require szenario/craft-observatory
php craft plugin/install observatory
```

## Quickstart 🚀

1. Open Settings → Plugins → Observatory
2. Enter your PostHog host, project ID and personal API key
3. After the initial sync, you are good to go!

## Console Commands

```bash
# pull yesterday into the local mirror
php craft observatory/sync/yesterday

# backfill the last X days (default 30), optional concurrency (default 2)
php craft observatory/sync/historical 30 2
```

## How the data gets there

Closed days are immutable, so Observatory fetches them from PostHog once and stores them in local tables. Today is never mirrored — it's still accumulating, so it's fetched live on every request and merged on top.

## Support

- Issues: https://github.com/szenario-fordesigners/craft-observatory/issues
- Source: https://github.com/szenario-fordesigners/craft-observatory
- Developer: https://www.szenario-design.com/

---

**Created by [szenario.design](https://www.szenario-design.com/)**
