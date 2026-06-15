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

---

## [3.1.0] - 2026-04-13

# Refactor BroadcastManager for improved functionality
- Updated BroadcastManager class to improve functionality and code structure.
- Added support for handling broadcast IDs, enhanced error handling, and refactored methods to accept optional chat IDs.

### Added:
- added `isActive()` to check active
- added option to set chatId as null

### Fixed:
- fixed `progress()` to update progress state from all methods

---

## [3.2.0] - 2026-06-13

### Added
- Edit last broadcast message with `editLastBroadcastForAll()`.
- Optional `broadcastId` targeting for editing or deleting the last message of a specific broadcast.
- Metadata peer loading for targeted edit/delete calls when `allUsers` is empty and `broadcastId` is provided.
- Pause/resume/cancel inline controls on live broadcast status messages.
- Scheduled broadcasts with durable jobs and runner methods.
- Self-destruct broadcasts with a `0` to `48` hour delay.
- Persisted per-broadcast metadata in `data/broadcasts/{broadcastId}.json`.
- Scheduled and self-destruct job runners.
- Internal error logging to `data/broadcast-errors.log`.

### Changed
- Safer state handling using shared state references by broadcast id.
- Safer cancel behavior: `cancel()` now marks cancellation without clearing in-flight requests.
- `progress()` now includes edit, scheduled, self-destruct, total, elapsed, and TPS fields.
- Message IDs are saved during broadcast after each successful peer instead of only at the end.
- `editLastBroadcastForAll()` and `deleteLastBroadcastForAll()` can use persisted broadcast metadata instead of only legacy `lastBroadcast.txt`.
- `deleteAllBroadcastsForAll()` now uses one progress loop instead of concurrent progress edits from workers.

### Fixed
- Pause/resume/cancel state reference issue.
- Workers not stopping after `done`.
- Unsafe watchdog behavior that could duplicate sends.
- Concurrent progress edits in `deleteAllBroadcastsForAll()`.

---

## [3.2.1] - 2026-06-13

### Added
- Added support for editing last broadcast messages with media loaded from `data/{adminId}/media.txt`.
- Added compatibility for passing saved media values / `botApiFileId` into `editLastBroadcastForAll()`.

### Changed
- Relaxed the `$media` parameter in `BroadcastManager::editLastBroadcastForAll()` so it is no longer limited to `?array`.
- Edit-last-broadcast flow can now reuse the same saved media format used by regular broadcast sending.

### Notes
- Passing `null` as media keeps the existing media unchanged.
- Passing a saved media value attempts to update the edited message media/caption.

---

## [3.2.2] - 2026-06-15

### Changed
* Reduced default broadcast concurrency from `20` to `10`.
* Reduced the maximum allowed concurrency limit from `50` to `30` to reduce pressure on the MadelineProto event loop during large broadcasts.
* Progress status messages are now edited every `5` seconds instead of every second.
* Progress status updates now also perform a final update when the operation reaches completion.
* Slowed down broadcast workers with a small delay between processed jobs.
* Added a delay after each media album chunk sent with `sendMultiMedia`.
* Added a delay between sequential messages sent to the same peer.
* Broadcast control buttons are now displayed in English:
  * `Pause`
  * `Resume`
  * `Cancel`

### Fixed
* Reduced the chance of `Timeout while waiting for updates.getChannelDifference` after heavy broadcasts.
* Reduced unnecessary progress-message edit calls during active broadcasts.
* Prevented noisy logs for harmless `MESSAGE_NOT_MODIFIED` errors during progress updates.
* Improved progress update stability by ignoring unchanged status edits instead of logging them as failures.

### Notes
* This release keeps the custom BroadcastManager flow, including saved message IDs, edit-last-broadcast, delete-last-broadcast, scheduled broadcasts, and self-destruct broadcasts.
* This is a stability and load-reduction update; it does not migrate to MadelineProto's official Broadcast API.

---
