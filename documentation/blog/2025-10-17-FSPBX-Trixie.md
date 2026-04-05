---
slug: debian-13-release-v1
title: New Release - v1.0.0 - Debian 13 Support!
authors: [lemstrom]
tags: [osupgrade]
---

We're excited to announce that Voxra now officially supports Debian 13 (Trixie)!


<!-- truncate -->

================================

🚀 Debian 13 ("Trixie") Support
------------------------------------------

### 🎉 Highlights

We're excited to announce that Voxra now officially supports Debian 13 (Trixie)!

This release ensures seamless installation and operation on the latest Debian platform, while maintaining backward compatibility with Debian 12 (Bookworm).

### 🧩 What's New

- Full compatibility with Debian 13 (Trixie)
- Updated install and update scripts to detect and configure the new OS codename
- Improved dependency handling and package checks for `systemd`, `iptables`, `snmpd`, and related services
- SignalWire token is now required for installation --- you will be prompted to enter it during setup. The token is stored at `~/.signalwire_token` for future runs.
- Refined logging and environment detection

### 🔧 Fixes & Improvements

-   Updated default paths and permission handling for new Debian configurations
-   Improved compatibility with FreeSWITCH 1.10.12
-   General code clean-up and logging enhancements
=======

-   Improved compatibility with FreeSWITCH 1.10.13
-   General code clean-up and logging enhancements
