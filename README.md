# BroadcastManager

**High-Performance Telegram Broadcast Manager** for [MadelineProto](https://docs.madelineproto.xyz/).
Manage Telegram broadcasts efficiently: send text, media, albums, inline buttons, pin/unpin messages, delete broadcasts, edit broadcast, schedule broadcasts, run self-destruct deletion jobs, and track live progress.

[![AGPL License](https://img.shields.io/badge/license-AGPL--3.0-blue.svg)](LICENSE)
[![Made with PHP](https://img.shields.io/badge/Made%20with-PHP-blue)](https://github.com/WizardLoop/BroadcastManager)
[![Packagist Version](https://img.shields.io/packagist/v/wizardloop/broadcastmanager)](https://packagist.org/packages/wizardloop/broadcastmanager)
[![Packagist Downloads](https://img.shields.io/packagist/dt/wizardloop/broadcastmanager?color=blue)](https://packagist.org/packages/wizardloop/broadcastmanager)
![CI](https://github.com/WizardLoop/BroadcastManager/actions/workflows/ci.yml/badge.svg)

---

## Requirements

* PHP `8.2` or newer
* `danog/madelineproto` `^8.4`
* `amphp/amp` `^3.0`

---

## Installation

```bash
composer require wizardloop/broadcastmanager
```

Include Composer autoload:

```php
require 'vendor/autoload.php';
```

Create the manager with your MadelineProto API instance:

```php
use BroadcastTool\BroadcastManager;

$manager = new BroadcastManager($api);
```

Optional custom data directory:

```php
BroadcastManager::setDataDir(__DIR__ . '/data'); // default: __DIR__ . '/../data'
```

---

## Features

* Concurrent broadcasts with safe concurrency clamping.
* Live progress message updates.
* Pause, resume, and cancel by broadcast id.
* Text, media, albums, entities, and inline buttons.
* Optional pinning of the last message sent to each peer.
* Delete last broadcast or all saved broadcast messages.
* Edit the last saved broadcast message for each peer.
* Scheduled broadcasts persisted to disk.
* Self-destruct broadcasts with automatic deletion after `0` to `48` hours.
* Per-broadcast metadata in `data/broadcasts/{broadcastId}.json`.
* FLOOD_WAIT retry handling and hard-fail handling.
* Internal error log at `data/broadcast-errors.log`.

---

## Basic Broadcast

```php
$users = ['123456789', '987654321'];

$messages = [
    [
        'message' => '<b>Hello subscribers</b>',
        'parse_mode' => 'HTML',
    ],
];

$broadcastId = $manager->broadcastWithProgress(
    allUsers: $users,
    messages: $messages,
    chatId: $adminChatId,
    pin: false,
    concurrency: 10
);
```

`broadcastWithProgress()` returns a string id. Use it with `progress()`, `pause()`, `resume()`, and `cancel()`.

```php
$progress = $manager->progress($broadcastId);
```

Concurrency is clamped internally:

* Minimum: `1`
* Maximum: `50`
* Default: `20`
* Recommended examples: `10`

---

## Message Payloads

Broadcast messages are passed directly to MadelineProto send methods with `peer` added internally.

### Text message

```php
$messages = [
    [
        'message' => '<b>Hello</b>',
        'parse_mode' => 'HTML',
    ],
];
```

### Inline buttons

```php
$messages = [
    [
        'message' => 'Click a button below:',
        'buttons' => [
            [['text' => 'Visit Website', 'url' => 'https://example.com']],
            [['text' => 'Start', 'callback_data' => 'start_action']],
        ],
    ],
];
```

`buttons` is converted to `reply_markup` internally for sending.

### Media message

```php
$messages = [
    [
        'message' => 'Photo caption',
        'media' => [
            '_' => 'inputMediaUploadedPhoto',
            'file' => '/path/to/photo.jpg',
        ],
        'parse_mode' => 'HTML',
    ],
];
```

When a message has `media`, BroadcastManager uses `messages->sendMedia()`. Otherwise it uses `messages->sendMessage()`.

### Album message

If a message contains `albumFile`, BroadcastManager reads that JSON file and sends it with `messages->sendMultiMedia()` in chunks of `10`.

```php
$messages = [
    [
        'albumFile' => __DIR__ . '/album.json',
    ],
];
```

Example `album.json` item:

```json
[
  {
    "media": {
      "type": "photo",
      "botApiFileId": "AgACAgQAAxkBA..."
    },
    "caption": "Album caption",
    "entities": []
  }
]
```

Supported album media types are mapped to `inputMediaPhoto` and `inputMediaDocument`.

---

## Progress

```php
$progress = $manager->progress($broadcastId);

if ($progress !== null) {
    echo "Progress: {$progress['progressPercent']}%\n";
    echo "Success: {$progress['success']}\n";
    echo "Failed: {$progress['failed']}\n";
    echo "Pending: {$progress['pending']}\n";
}
```

`progress()` returns `array|null`:

```php
[
    'processed' => 0,
    'success' => 0,
    'failed' => 0,
    'pending' => 0,
    'flood' => 0,
    'progressPercent' => 0.0,
    'breakdown' => [
        'sent' => 0,
        'deleted' => 0,
        'unpin' => 0,
        'edited' => 0,
        'unchanged' => 0,
        'scheduled' => 0,
    ],
    'edited' => 0,
    'unchanged' => 0,
    'scheduled' => 0,
    'selfDestruct' => null,
    'type' => 'send',
    'total' => 0,
    'elapsed' => 0.0,
    'tps' => 0.0,
    'done' => false,
    'paused' => false,
    'cancel' => false,
    'startedAt' => null,
]
```

---

## Control Running Operations

```php
$manager->pause($broadcastId);
$manager->resume($broadcastId);
$manager->cancel($broadcastId);
```

`cancel()` only marks the operation as cancelled. It does not clear in-flight Telegram requests.

Check operation state:

```php
$manager->isActive($broadcastId);
$manager->isPaused($broadcastId);
$manager->isCancelled($broadcastId);
```

Pause, resume, cancel, and progress work with send/edit/delete/unpin operations that have a live state id.

---

## Pin And Unpin

Pin the last message sent to each peer:

```php
$broadcastId = $manager->broadcastWithProgress(
    allUsers: $users,
    messages: $messages,
    chatId: $adminChatId,
    pin: true,
    concurrency: 10
);
```

Unpin all messages for all peers:

```php
$unpinId = $manager->unpinAllMessagesForAll($users, $adminChatId, 10);
```

---

## Delete Broadcast Messages

Delete the last saved broadcast message for each peer using `data/{peer}/lastBroadcast.txt`:

```php
$deleteId = $manager->deleteLastBroadcastForAll($users, $adminChatId, 10);
```

Delete the last message from a specific broadcast using `data/broadcasts/{broadcastId}.json`:

```php
$deleteId = $manager->deleteLastBroadcastForAll(
    allUsers: $users,
    chatId: $adminChatId,
    concurrency: 10,
    broadcastId: $broadcastId
);
```

If you pass an empty `allUsers` array with `broadcastId`, peers are loaded from that broadcast metadata:

```php
$deleteId = $manager->deleteLastBroadcastForAll(
    allUsers: [],
    chatId: $adminChatId,
    concurrency: 10,
    broadcastId: $broadcastId
);
```

When `broadcastId` is provided, the legacy `lastBroadcast.txt` file is not used and is not removed. This prevents a newer broadcast from being affected by an older delete request.

Delete all saved broadcast messages for each peer using `data/{peer}/messages.txt`:

```php
$deleteAllId = $manager->deleteAllBroadcastsForAll($users, $adminChatId, 10);
```

Check whether legacy saved message files exist:

```php
$hasLast = $manager->hasLastBroadcast();
$hasAll = $manager->hasAllBroadcast();
```

---

## Edit Last Broadcast

`editLastBroadcastForAll()` reads each peer's `data/{peer}/lastBroadcast.txt` and edits that message id by default.

```php
$editId = $manager->editLastBroadcastForAll(
    allUsers: $users,
    newText: '<b>Updated text</b>',
    chatId: $adminChatId,
    buttons: null,
    media: null,
    concurrency: 10,
    parseMode: 'HTML'
);
```

Edit the last message from a specific broadcast using `data/broadcasts/{broadcastId}.json`:

```php
$editId = $manager->editLastBroadcastForAll(
    allUsers: $users,
    newText: '<b>Updated text for a specific broadcast</b>',
    chatId: $adminChatId,
    buttons: null,
    media: null,
    concurrency: 10,
    parseMode: 'HTML',
    broadcastId: $broadcastId
);
```

If you pass an empty `allUsers` array with `broadcastId`, peers are loaded from that broadcast metadata:

```php
$editId = $manager->editLastBroadcastForAll(
    allUsers: [],
    newText: '<b>Updated text for the stored broadcast peers</b>',
    chatId: $adminChatId,
    buttons: null,
    media: null,
    concurrency: 10,
    parseMode: 'HTML',
    broadcastId: $broadcastId
);
```

With buttons:

```php
$editId = $manager->editLastBroadcastForAll(
    allUsers: $users,
    newText: 'Updated with a button',
    chatId: $adminChatId,
    buttons: [
        [['text' => 'Open', 'url' => 'https://example.com']],
    ],
    media: null,
    concurrency: 10,
    parseMode: 'HTML'
);
```

Edit counters include:

* `edited`
* `unchanged`
* `failed`
* `flood`

`MESSAGE_NOT_MODIFIED` is counted as `unchanged`, not `failed`.

---

## Scheduled Broadcasts

Scheduled broadcasts are saved in `data/scheduled-broadcasts.json`.

```php
$scheduleId = $manager->scheduleBroadcastForAll(
    allUsers: $users,
    messages: [
        ['message' => 'Scheduled hello'],
    ],
    scheduledAt: time() + 3600,
    chatId: $adminChatId,
    pin: false,
    concurrency: 10,
    selfDestructHours: null
);
```

If `scheduledAt` is in the future, the broadcast is saved and not sent yet.
If `scheduledAt <= time()`, it is marked `running`, executed immediately, and then marked `done`, `cancelled`, or `failed`.

Run due scheduled broadcasts periodically:

```php
$results = $manager->runDueScheduledBroadcasts();
```

List scheduled broadcasts:

```php
$scheduled = $manager->listScheduledBroadcasts();
```

Cancel a scheduled broadcast that has not started:

```php
$cancelled = $manager->cancelScheduledBroadcast($scheduleId);
```

`cancelScheduledBroadcast()` returns `false` once the job is already `running`, `done`, `cancelled`, or `failed`.

---

## Self-Destruct Broadcasts

Pass `selfDestructHours` as the sixth argument to `broadcastWithProgress()`.

```php
$broadcastId = $manager->broadcastWithProgress(
    allUsers: $users,
    messages: [
        ['message' => 'This message will be removed later.'],
    ],
    chatId: $adminChatId,
    pin: false,
    concurrency: 10,
    selfDestructHours: 6
);
```

Rules:

* `null` means no automatic deletion.
* `0` means delete immediately after the broadcast finishes.
* `1` through `48` means delete after that many hours.

Invalid values below `0` or above `48` throw `InvalidArgumentException`.

Run due self-destruct jobs periodically:

```php
$results = $manager->runDueSelfDestructJobs();
```

List self-destruct jobs:

```php
$jobs = $manager->listSelfDestructJobs();
```

Cancel a self-destruct job that has not started:

```php
$cancelled = $manager->cancelSelfDestructJob($jobId);
```

Self-destruct deletes by `data/broadcasts/{broadcastId}.json`, not by `lastBroadcast.txt`. If a newer broadcast was sent later, the self-destruct job still deletes only the messages from its own broadcast id.

If a broadcast is cancelled midway, the self-destruct job is created only for messages that were actually sent and saved in metadata.

---

## Periodic Runners

Scheduled broadcasts and self-destruct jobs are durable, but they run only when you call the runners.
Call them from your bot loop, event handler, or internal cron-style task:

```php
$manager->runDueScheduledBroadcasts();
$manager->runDueSelfDestructJobs();
```

---

## Filter Peers

```php
$filterSub = $manager->filterPeers($users, 'users');

$targets = $filterSub['targets']; // array
$failed = $filterSub['failed'];   // int
$total = $filterSub['total'];     // int
```

Supported filter types:

* `users`
* `groups`
* `channels`
* `all`

---

## Last Broadcast Data

`lastBroadcastData()` returns the latest saved status text from `data/LastBrodDATA.txt`, or `false` if it does not exist.

```php
$lastData = $manager->lastBroadcastData();
```

---

## Data Files

Legacy files are still written for backward compatibility:

* `data/{peer}/lastBroadcast.txt`
* `data/{peer}/messages.txt`
* `data/LastBrodDATA.txt`

New files:

* `data/broadcasts/{broadcastId}.json`
* `data/scheduled-broadcasts.json`
* `data/self-destruct-jobs.json`
* `data/broadcast-errors.log`

Example broadcast metadata:

```json
{
  "id": "2b9a24c5ef2cbb10",
  "type": "send",
  "createdAt": 1710000000,
  "status": "done",
  "total": 100,
  "sent": 90,
  "failed": 10,
  "peers": {
    "12345": {
      "lastMessageId": 111,
      "messageIds": [111, 112],
      "status": "sent"
    }
  },
  "selfDestruct": {
    "enabled": true,
    "hours": 6,
    "deleteAt": 1710021600,
    "deleteJobId": "selfdestruct_..."
  }
}
```

---

## Public API Reference

```php
public function __construct(API $api);

public function broadcastWithProgress(
    array $allUsers,
    array $messages,
    $chatId = null,
    bool $pin = false,
    int $concurrency = 20,
    ?int $selfDestructHours = null
): string;

public function editLastBroadcastForAll(
    array $allUsers,
    string $newText,
    $chatId = null,
    ?array $buttons = null,
    ?array $media = null,
    int $concurrency = 20,
    string $parseMode = 'HTML',
    ?string $broadcastId = null
): string;

public function scheduleBroadcastForAll(
    array $allUsers,
    array $messages,
    int $scheduledAt,
    $chatId = null,
    bool $pin = false,
    int $concurrency = 20,
    ?int $selfDestructHours = null
): string;

public function runDueScheduledBroadcasts(): array;
public function cancelScheduledBroadcast(string $scheduleId): bool;
public function listScheduledBroadcasts(): array;

public function deleteLastBroadcastForAll(
    array $allUsers,
    $chatId = null,
    int $concurrency = 20,
    ?string $broadcastId = null
): string;
public function deleteAllBroadcastsForAll(array $allUsers, $chatId = null, int $concurrency = 20): string;
public function unpinAllMessagesForAll(array $allUsers, $chatId = null, int $concurrency = 20): string;

public function runDueSelfDestructJobs(): array;
public function cancelSelfDestructJob(string $jobId): bool;
public function listSelfDestructJobs(): array;

public function pause(string $id): void;
public function resume(string $id): void;
public function cancel(string $id): void;

public function isPaused(string $id): bool;
public function isCancelled(string $id): bool;
public function isActive(?string $id = null): bool;

public function hasLastBroadcast(): bool;
public function hasAllBroadcast(): bool;
public function progress(?string $id = null): ?array;
public function lastBroadcastData(): string|false;
public function filterPeers(array $allUsers, string $filterType = 'users'): array;

public static function setDataDir(string $path): void;
```

---

## Error Handling

Hard-fail Telegram RPC errors are counted as failed and are not retried.
FLOOD_WAIT errors increase the job attempt count, set a future retry time, and are retried up to three attempts.

Internal logging is written to:

```text
data/broadcast-errors.log
```

Log write failures are ignored so they do not crash the bot.

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for updates.

---

## License

**GNU AGPL-3.0** - see [LICENSE](LICENSE).
