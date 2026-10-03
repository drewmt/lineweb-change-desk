# Support policy

## Compatibility

Each plugin supports the WordPress, WooCommerce, and PHP versions declared in its plugin header and `readme.txt`. The current repository baseline is:

- WordPress 6.9 or newer;
- PHP 8.3 or newer;
- WooCommerce 10.8 or newer for Commerce Suite, Checkout GR, and Store Guard.

The individual plugin README is the authoritative source for product-specific requirements and known limitations.

## What support covers

Support covers reproducible defects in the latest published plugin version on an otherwise supported installation. Useful reports include:

- the plugin and version;
- WordPress, WooCommerce, PHP, theme, and browser versions;
- exact steps to reproduce the problem;
- the expected and actual result;
- relevant logs with credentials and personal data removed;
- whether the issue remains with a default WordPress theme and unrelated plugins disabled on a staging site.

Use the repository issue tracker for non-sensitive defects and feature discussions. Follow the [security policy](SECURITY.md) for anything that may expose data, permissions, or site integrity.

## What support does not cover

- custom development or theme-specific redesign;
- unsupported template overrides or third-party plugin conflicts that require custom integration work;
- hosting, DNS, email, or server administration;
- tax, accounting, or legal advice;
- third-party API accounts, credentials, availability, or pricing;
- recovery of an already compromised or unsupported installation.

Reproduce risky changes on staging first and keep a current backup. Never post credentials, personal data, customer orders, or production database exports in a public issue.
