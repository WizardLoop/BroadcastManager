# BroadcastManager

**High-Performance Telegram Broadcast Manager** for [MadelineProto](https://docs.madelineproto.xyz/).
Manage broadcasts efficiently: send messages, media albums, pin/unpin messages, control broadcasts in real-time, and track live progress with advanced features.

[![AGPL License](https://img.shields.io/badge/license-AGPL--3.0-blue.svg)](LICENSE)
[![Made with ❤️](https://img.shields.io/badge/Made%20with-%E2%9D%A4%EF%B8%8F-blue)](https://github.com/WizardLoop/BroadcastManager)
[![Packagist
Version](https://img.shields.io/packagist/v/wizardloop/broadcastmanager)](https://packagist.org/packages/wizardloop/broadcastmanager)
[![Packagist Downloads](https://img.shields.io/packagist/dt/wizardloop/broadcastmanager?color=blue)](https://packagist.org/packages/wizardloop/broadcastmanager)
![CI](https://github.com/WizardLoop/BroadcastManager/actions/workflows/ci.yml/badge.svg)

---

## 🌟 Features

* 🚀 **High-Performance Broadcasts**
  Send messages concurrently to thousands of users, groups, or channels with configurable concurrency.

* ⏸ **Pause / Resume / Cancel Broadcasts**
  Control ongoing broadcasts in real-time without restarting.

* 📌 **Pin & Unpin Messages**

  * Pin the last broadcasted message automatically.
  * Unpin all messages for all subscribers.

* 🧹 **Delete Last Broadcast**

  * Remove previously sent messages from all users.
  * Retries failed deletions and handles Telegram API limits automatically.

* ♻️ **Delete All Broadcasts**

  * Remove previously all sent messages from all users.
  * Retries failed deletions and handles Telegram API limits automatically.
  
* 📊 **Live Progress Tracking**

  * Visual progress bars
  * Messages per second (TPS)
  * Sent, failed, and pending counts
  * Paused/cancelled indicators

* 🖼 **Media Albums Support**
  * Send multiple images/documents in a single broadcast using `sendMultiMedia`.
  * Supports captions and message entities.

* 💾 **Saving & Reusing Albums**  
  Save albums to a JSON file for reuse in future broadcasts.  
  Use [album-bot](https://github.com/WizardLoop/album-bot/blob/main/poll-bot.php) as an example to store album files locally.

* 🛡 **FLOOD_WAIT Handling & Retries**
  Automatically respects Telegram rate limits and retries failed messages.

* 🔘 **Inline Buttons / Reply Markup**

  * Include interactive buttons for links, commands, or actions.

---

## ⚡ Installation

```bash
composer require wizardloop/broadcastmanager
```

Include autoload:

```php
require 'vendor/autoload.php';
```

---

## 🚀 Usage Example

---
## Send Broadcast

### 1) live progress update in message to admin:
```php
use BroadcastTool\BroadcastManager;

$manager = new BroadcastManager($api);

$manager->broadcastWithProgress($users, $messages, $adminChatId, true, 20);
```

### 2) track on progress without message:
This method returns an integer ID that can be used.

```php
use BroadcastTool\BroadcastManager;

$manager = new BroadcastManager($api);

$broadcastId = $manager->broadcastWithProgress($users, $messages, null, true, 20);

/**
 * Get progress (can be polled)
 */
$progress = $manager->progress($broadcastId);

if ($progress !== null) {

    // 📊 Core stats
    $processed = $progress['processed'];
    $success   = $progress['success'];
    $failed    = $progress['failed'];
    $pending   = $progress['pending'];
    $flood     = $progress['flood'];

    // 📈 Progress %
    $progressPercent = $progress['progressPercent'];

    // 📦 Breakdown
    $sent    = $progress['breakdown']['sent'];
    $deleted = $progress['breakdown']['deleted'];
    $unpin   = $progress['breakdown']['unpin'];

    // ⚙️ State
    $done    = $progress['done'];
    $paused  = $progress['paused'];
    $cancel  = $progress['cancel'];

    // ⏱ Timing
    $startedAt = $progress['startedAt'];

    /**
     * Example usage
     */
    echo "Progress: {$progressPercent}%\n";
    echo "Sent: {$sent}\n";
    echo "Failed: {$failed}\n";

    if ($done) {
        echo "Broadcast finished!";
    }

    if ($paused) {
        echo "Broadcast paused...";
    }
}
```

```php
* progress return array|null {
*   processed: int,           // total processed items (sent + deleted + unpin + failed)
*   success: int,             // successful operations (sent + deleted + unpin)
*   failed: int,              // failed operations count
*   pending: int,             // remaining items in queue
*   flood: int,               // FLOOD_WAIT occurrences
*
*   progressPercent: float,   // completion percentage (processed / total)
*
*   breakdown: array {
*      sent: int,
*      deleted: int,
*      unpin: int
*   },
*
*   done: bool,               // process finished
*   paused: bool,            // process paused
*   cancel: bool,            // process cancelled
*
*   startedAt: float         // microtime start timestamp
* }
```

---

## Filer Peers
```php
$filterSub = $manager->filterPeers($users, 'users');
$targets = $filterSub['targets']; # array
$failed = $filterSub['failed']; # int
$total = $filterSub['total']; # int
```

---

## ⏸ Control Broadcasts

```php
$manager->pause($broadcastId);
$manager->resume($broadcastId);
$manager->cancel($broadcastId);
```

### Check state:
```php
if ($manager->isActive($broadcastId));
if ($manager->isPaused($broadcastId));
if ($manager->isCancelled($broadcastId));
if (!$manager->hasLastBroadcast($broadcastId));
if (!$manager->hasAllBroadcast($broadcastId));
print_r($manager->progress($broadcastId));
```

### Set data dir:
```php
BroadcastManager::setDataDir(__DIR__ . '/data'); // default: __DIR__ . '/../data'
```

---

## 🧹 Delete Last Broadcast

```php
$broadcastId = $manager->deleteLastBroadcastForAll($users, $adminChatId, 20);
```

---

## ♻️ Delete All Broadcast

```php
$broadcastId = $manager->deleteAllBroadcastsForAll($users, $adminChatId, 20);
```

---

## 📊 Get Last Broadcast Data

```php
$broadcastId = $manager->lastBroadcastData();
```

---

## 📌 Pin / Unpin Messages

## Pin last broadcast automatically:

```php
$broadcastId = $manager->broadcastWithProgress(..., pin: true);
```

## Unpin all messages:

```php
$broadcastId = $manager->unpinAllMessagesForAll(...);
```

---

## 🔘 Inline Buttons & Reply Markup

```php
$message = [
    'message' => "Click a button below:",
    'buttons' => [
        [['text' => "Visit Website", 'url' => "https://example.com"]],
        [['text' => "Start", 'callback_data' => "start_action"]]
    ]
];
```

---

## ⚙️ Advanced Options

* **Concurrency** - Number of parallel workers.
* **Filter Types** - 'users', 'groups', 'channels', 'all'
* **Album Handling** - JSON-based albums with multiple media files.
* **Retries & Delays** - Automatic retries with backoff.
* **Progress Tracking** - Real-time broadcast stats with `progress()`.

---

## 🤝 Contributing

1. Fork repo
2. Create branch: `git checkout -b feature/my-feature`
3. Commit changes: `git commit -m "Add feature"`
4. Push branch: `git push origin feature/my-feature`
5. Open Pull Request

---

## 📄 License

**GNU AGPL-3.0** - see [LICENSE](LICENSE).

---

## 📝 Changelog

See [CHANGELOG.md] for updates.

---

✅ **Pro Tips**

* Use `pin: true` to pin important broadcasts.
* Include `buttons` for interactive messages.
* Adjust `concurrency` for optimal performance.
* Use `pause/resume/cancel` for safe broadcast control.
