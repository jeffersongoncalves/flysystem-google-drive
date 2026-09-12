# Changelog

All notable changes to `flysystem-google-drive` will be documented in this file.

## 1.0.0 - 2026-09-12

Initial release.

A League Flysystem v3 adapter for Google Drive, with a zero-config Laravel driver. Built to replace the unmaintained masbug/flysystem-google-drive-ext — fixes the isReachable()-throws-on-missing-folder bug, delete() swallowing real API errors, stale path cache after move/rename, and unescaped query injection.
