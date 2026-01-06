# README.md

# 📨 BroadcastManager

**High-Performance Telegram Broadcast Manager** for [MadelineProto](https://docs.madelineproto.xyz/).
Manage broadcasts efficiently: send messages, media albums, pin/unpin messages, control broadcasts in real-time, and track live progress with advanced features.

[![AGPL License](https://img.shields.io/badge/license-AGPL--3.0-blue.svg)](LICENSE)
[![Made with ❤️](https://img.shields.io/badge/Made%20with-%E2%9D%A4%EF%B8%8F-blue)](https://github.com/WizardLoop/BroadcastManager)
[![Packagist
Version](https://img.shields.io/packagist/v/wizardloop/broadcastmanager)](https://packagist.org/packages/wizardloop/broadcastmanager)
[![Packagist Downloads](https://img.shields.io/packagist/dt/wizardloop/broadcastmanager?color=blue)](https://packagist.org/packages/wizardloop/broadcastmanager)

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

* 📊 **Live Progress Tracking**

  * Visual progress bars
  * Messages per second (TPS)
  * Sent, failed, and pending counts
  * Paused/cancelled indicators

* 🖼 **Media Albums Support**

  * Send multiple images/documents in a single broadcast using `sendMultiMedia`.
  * Supports captions and message entities.

* 🛡 **FLOOD_WAIT Handling & Retries**
  Automatically respects Telegram rate limits and retries failed messages.

* 🔘 **Inline Buttons / Reply Markup**

  * Include interactive buttons for links, commands, or actions.

---

## 📁 Repository Structure

```
BroadcastManager/
├── src/
│   └── BroadcastManager.php
├── data/
│   └── .gitkeep
├── composer.json
├── README.md
├── LICENSE
└── CHANGELOG.md
```

---

## 💻 Requirements

* [MadelineProto](https://docs.madelineproto.xyz/)
* [amphp/amp](https://amphp.org/)

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

```php
use BroadcastTool\BroadcastManager;

$manager = new BroadcastManager($api);

$manager->broadcastWithProgress(
    allUsers: $users,
    messages: $messages,
    chatId: $adminChatId,
    filterType: 'users',
    pin: true,
    concurrency: 25
);
```

---

## ⏸ Control Broadcasts

```php
$manager->pause();
$manager->resume();
$manager->cancel();
```

Check state:

```php
if ($manager->isPaused()) echo "Paused";
if ($manager->isCancelled()) echo "Cancelled";
print_r($manager->progress());
```

---

## 🧹 Delete Last Broadcast

```php
$manager->deleteLastBroadcastForAll(
    allUsers: $users,
    chatId: $adminChatId,
    concurrency: 20
);
```

---

## 📌 Pin / Unpin Messages

Pin last broadcast automatically:

```php
$manager->broadcastWithProgress(..., pin: true);
```

Unpin all messages:

```php
$manager->unpinAllMessagesForAll(...);
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

* **Concurrency** – Number of parallel workers.
* **Filter Types** – 'users', 'groups', 'channels', 'all'
* **Album Handling** – JSON-based albums with multiple media files.
* **Retries & Delays** – Automatic retries with backoff.
* **Progress Tracking** – Real-time broadcast stats with `progress()`.

---

## 🤝 Contributing

1. Fork repo
2. Create branch: `git checkout -b feature/my-feature`
3. Commit changes: `git commit -m "Add feature"`
4. Push branch: `git push origin feature/my-feature`
5. Open Pull Request

---

## 📄 License

**GNU AGPL-3.0** — see [LICENSE](LICENSE).

---

## 📝 Changelog

See [CHANGELOG.md] for updates.

---

✅ **Pro Tips**

* Use `pin: true` to pin important broadcasts.
* Include `buttons` for interactive messages.
* Adjust `concurrency` for optimal performance.
* Use `pause/resume/cancel` for safe broadcast control.
