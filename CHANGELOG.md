# Changelog

All notable changes to this project will be documented in this file.

## [1.0.0] - 2026-01-06

### Added

* Initial release of BroadcastManager
* High-performance broadcast system with concurrency
* Pause, Resume, Cancel broadcast control
* Pin/unpin messages for all subscribers
* Delete last broadcast for all users
* Live progress tracking with TPS and progress bar
* Support for sending media albums (`sendMultiMedia`)
* Inline buttons support for interactive messages
* Automatic FLOOD_WAIT handling and retry system

---

## [1.0.1] - 2026-01-07

### Added

* use statement for MadelineProto API

---

## [1.0.2] - 2026-01-07

### Added

* Implement hasLastBroadcast method

---

## [1.0.3] - 2026-01-07

### Fixed

* Refactor hasLastBroadcast to check for saved messages
Update hasLastBroadcast method to check for last broadcast messages in data directory.

---

## [1.0.4] - 2026-01-07

### Fixed

* Refactor API calls in BroadcastManager methods

---

## [2.0.0] - 2026-01-07

## Version 2.0.0 - Async Upgrade

### What's new

- All broadcast, deletion, and unpinning operations now run **asynchronously** using Amp.
- Progress updates are sent **live** during execution.
- Added support for **pause**, **resume**, and **cancel** for running broadcasts.
- Improved handling of `FLOOD_WAIT` errors without stopping other tasks.
- Introduced **concurrency control** for sending messages, deleting broadcasts, and unpinning messages.
- All file operations (reading/writing albums and last broadcast messages) now use `Amp\File`.

---

## [2.0.1] - 2026-01-07

### What's new

* Enhance error handling in BroadcastManager

---

## [2.0.2] - 2026-01-07

### Fixed:

* Track failed counts in BroadcastManager methods

---

## [2.0.3] - 2026-01-07

### Fixed:

* Eliminate unnecessary count of failedTargets
Remove redundant count of failed targets.

---

## [2.0.4] - 2026-01-07

### Added:

* Add method to delete all broadcasts for users
Implement deleteAllBroadcastsForAll method to delete all broadcast messages for users concurrently and update the status message during the process.

---

## [2.0.5] - 2026-01-08

### Added:

* Enhance status messaging in BroadcastManager
Added functionality to send initial status messages when gathering peers and starting broadcasts. Updated the methods to edit messages instead of sending new ones for status updates.

---

## [3.0.0] - 2026-01-11

## Version 3.0.0 - Async Upgrade

### Added:
* Added `hasAllBroadcast()`.

### Improvements:
* Watchdog monitors stuck jobs and re-enqueues them.
* Improved file handling for `lastBroadcast.txt` and `messages.txt`.
* Modularized code structure for readability and maintainability.

### Fixed:
* Better error handling for missing files or blocked users.
* Proper cleanup of message files after deletion.

---

## [3.0.1] - 2026-01-11

### Fixed:

* lastBroadcastData

---

## [3.0.2] - 2026-01-11

### Added:

* setDataDir & getDataDir

---

## [3.0.3] - 2026-01-11

### Fixed:

* Fix getDataDir() to handle uninitialized $dataDir

---

## [3.0.4] - 2026-01-13

### Added & Fixed:

* Extracted peer filtering from broadcast execution
* Reduced unnecessary processing during broadcasts

---

## [3.0.5] - 2026-01-18

### Added & Fixed:

* Handle additional RPCErrorException cases
