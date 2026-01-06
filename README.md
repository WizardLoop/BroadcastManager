# 📨 BroadcastManager
High-performance Telegram broadcast manager for MadelineProto.

[![AGPL License](https://img.shields.io/badge/license-AGPL--3.0-blue.svg)](LICENSE)
[![Made with ❤️](https://img.shields.io/badge/Made%20with-%E2%9D%A4%EF%B8%8F%20-blue)](https://github.com/WizardLoop/BroadcastManager)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-blue)](https://www.php.net/)

---

## 📦 Features

* 🚀 High‑performance broadcasts with concurrency
* ⏸ Pause / ▶ Resume / 🛑 Cancel broadcast
* 📌 Pin / Unpin messages for all subscribers
* 🧹 Delete last broadcast everywhere
* 📊 Live progress updates (TPS, progress bar, failures)
* 🖼 Albums (sendMultiMedia)
* 🛡 FLOOD_WAIT handling & retries

---

## 📁 Repository Structure

```
telegram-broadcast-manager/
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

## 📦 Installation

```bash
composer require wizardloop/broadcastmanager
```

---

## 🚀 Usage Example

```php
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

## ⏸ Control Broadcast

```php
$manager->pause();
$manager->resume();
$manager->cancel();
```

---

## 🧹 Delete Last Broadcast

```php
$manager->deleteLastBroadcastForAll($users, $adminChatId);
```

---

## 📌 Unpin All Messages

```php
$manager->unpinAllMessagesForAll($users, $adminChatId);
```

---

## 🤝 Contributing

Pull requests are welcome!

1. Fork the repo
2. Create a branch: `git checkout -b fix/my-fix`
3. Commit: `git commit -m 'Fix something'`
4. Push: `git push origin fix/my-fix`
5. Open a PR 🙌

---

## 📄 License

Licensed under the **GNU AGPL-3.0** — see [`LICENSE`](LICENSE).
