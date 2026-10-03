Lineweb Change Desk translations

WordPress.org install packages do not bundle translated catalogs. Core loads
approved language packs from wp-content/languages/plugins/, with English fallback
when a translation is unavailable. No Greek directory language pack is approved yet.

The public development repository retains POT/PO source and native el/el_GR
catalogs. The separate direct-install ZIP includes those catalogs. This directory
is retained for the plugin's declared Domain Path and direct-install compatibility.

Readable source and build instructions:
https://github.com/drewmt/lineweb-change-desk

Synthetic catalog fixtures in the test suite verify PHP and hashed JS language-pack
loading. They are not WordPress.org approval or evidence of pack availability.
