<?php

namespace BroadcastTool;

use danog\MadelineProto\API;
use Amp\Loop;
use Amp\File;
use danog\MadelineProto\RPCErrorException;
use SplQueue;

class BroadcastManager
{
    private API $api;
    private ?array $currentBroadcastState = null;
    private array $albumTimers = [];

    public function __construct(API $api)
    {
        $this->api = $api;
    }

    /**
     * Broadcast messages to users/channels with progress tracking
     */
    public function broadcastWithProgress(
        array $allUsers,
        array $messages,
        $chatId,
        string $filterType = 'users',
        bool $pin = false,
        int $concurrency = 20
    ): array {
        $api = $this->api;

        $targets = [];
        foreach ($allUsers as $peer) {
            try {
                $info = $api->getInfo($peer);
                $type = $info['type'] ?? 'user';

$allowedTypesByFilter = [
    'all' => ['user', 'bot', 'chat', 'supergroup', 'channel'],
    'users' => ['user', 'bot'],
    'groups' => ['chat', 'supergroup'],
    'channels' => ['channel'],
];

$allowed = $allowedTypesByFilter[$filterType] ?? ['user', 'bot'];

if (in_array($type, $allowed, true)) {
    $targets[] = (string) $peer;
}
				
            } catch (\Throwable) { continue; }
        }

        $total = count($targets);
        $state = [
            'sent' => 0,
            'failed' => 0,
            'flood' => 0,
            'queue' => new \SplQueue(),
            'lastMessageIds' => [],
            'paused' => false,
            'cancel' => false,
            'done' => false,
            'startedAt' => microtime(true),
        ];

        foreach ($targets as $peer) {
            $state['queue']->enqueue(['peer' => $peer, 'attempts' => 0]);
        }

        $status = $api->messages->sendMessage([
            'peer' => $chatId,
            'message' => '⌛ Starting broadcast...',
            'parse_mode' => 'HTML'
        ]);
        $statusId = $api->extractMessageId($status);

        \Amp\async(function () use ($api, $chatId, $statusId, &$state, $total) {
            $lastText = '';
            while (!$state['done']) {
                $processed = $state['sent'] + $state['failed'];
                $pending   = $total - $processed;
                $elapsed   = microtime(true) - $state['startedAt'];
                $eta       = $processed > 0 ? (int)(($elapsed / $processed) * $pending) : 0;
                $tps       = $elapsed > 0 ? round($state['sent'] / $elapsed, 2) : 0;

                $text =
                    "<b>📊 Broadcast Progress</b>\n\n".
                    "<code>".$this->progressBar($processed, $total)."</code>\n\n".
                    "📨 Sent: {$state['sent']} / $total\n".
                    "❌ Failed: {$state['failed']}\n".
                    "⏳ Pending: $pending\n".
                    "⚡ TPS: {$tps} msg/s\n".
                    ($state['paused'] ? "\n⏸ <b>Paused</b>" : '').
                    ($state['cancel'] ? "\n🛑 <b>Cancelled</b>" : '');

                if ($text !== $lastText) {
                    try {
                        $api->messages->editMessage([
                            'peer' => $chatId,
                            'id' => $statusId,
                            'message' => $text,
                            'parse_mode' => 'HTML'
                        ]);
                        $lastText = $text;
                    } catch (\Throwable) {}
                }
                $api->sleep(1);
            }
        });

        for ($i = 0; $i < $concurrency; $i++) {
            \Amp\async(function () use ($api, &$state, $messages, $pin) {
                while (!$state['queue']->isEmpty()) {
                    if ($state['cancel']) return;
                    while ($state['paused']) $api->sleep(1);

                    $job = $state['queue']->dequeue();
                    $peer = $job['peer'];
                    $attempts = $job['attempts'];
                    $lastMessageId = null;

                    try {
                        $albumMessages = [];

                        foreach ($messages as $message) {
                            if (isset($message['albumFile']) && file_exists($message['albumFile'])) {
                                $album = json_decode(\Amp\File\read($message['albumFile']), true);
                                if ($album) $albumMessages = $album;
                            }
                        }

                        if ($albumMessages) {
                            $chunks = array_chunk($albumMessages, 10);
                            foreach ($chunks as $chunk) {
                                $multiMedia = [];
                                foreach ($chunk as $item) {
                                    $m = $item['media'];
                                    $mediaArray = $m['type'] === 'photo'
                                        ? ['_' => 'inputMediaPhoto', 'id' => $m['botApiFileId']]
                                        : ['_' => 'inputMediaDocument', 'id' => $m['botApiFileId']];

                                    $multiMedia[] = [
                                        '_' => 'inputSingleMedia',
                                        'media' => $mediaArray,
                                        'message' => $item['caption'] ?? '',
                                        'entities' => $item['entities'] ?? []
                                    ];
                                }

                                $sentUpdates = $api->messages->sendMultiMedia(peer: $peer, multi_media: $multiMedia);
                                foreach ($sentUpdates as $upd) {
                                    $lastMessageId = $api->extractMessageId($upd);
                                }
                            }

                        } else {
                            foreach ($messages as $message) {
                                $method = isset($message['media']) ? 'sendMedia' : 'sendMessage';
                                $payload = $message;
                                $payload['peer'] = $peer;
                                $payload['floodWaitLimit'] = 2*86400;

                                if (isset($message['buttons'])) {
                                    $payload['reply_markup'] = $message['buttons'];
                                }

                                $res = $api->messages->$method($payload);
                                $lastMessageId = $api->extractMessageId($res);
                            }
                        }

                        if ($pin && $lastMessageId) {
                            $api->messages->updatePinnedMessage([
                                'peer' => $peer,
                                'id' => $lastMessageId,
                                'unpin' => false
                            ]);
                        }

                        $state['sent']++;
                        $state['lastMessageIds'][$peer] = (string)$lastMessageId;

                    } catch (\danog\MadelineProto\RPCErrorException $e) {
                        if (str_contains($e->getMessage(), 'FLOOD_WAIT')) {
                            $state['flood']++;
                            preg_match('/FLOOD_WAIT_(\d+)/', $e->getMessage(), $m);
                            $api->sleep((int)($m[1] ?? 5));
                            $state['queue']->enqueue(['peer'=>$peer,'attempts'=>$attempts]);
                            continue;
                        }

                        if ($attempts >= 3) {
                            $state['failed']++;
                        } else {
                            $state['queue']->enqueue(['peer'=>$peer,'attempts'=>$attempts + 1]);
                            $api->sleep(0.5);
                        }

                    } catch (\Throwable) {
                        $state['failed']++;
                    }
                }
            });
        }

        while (!$state['cancel'] && ($state['sent'] + $state['failed']) < $total) {
            $api->sleep(1);
        }

    $state['done'] = true;

    $processed = $state['sent'] + $state['failed'];
    $pending   = $total - $processed;
    $elapsed   = microtime(true) - $state['startedAt'];
    $eta       = 0;
    $tps       = $elapsed > 0 ? round($state['sent'] / $elapsed, 2) : 0;

    $finalText =
        "<b>📊 Broadcast Progress</b>\n\n".
        "<code>".$this->progressBar($processed, $total)."</code>\n\n".
        "📨 Sent: {$state['sent']} / $total\n".
        "❌ Failed: {$state['failed']}\n".
        "⏳ Pending: $pending\n".
        "⚡ TPS: {$tps} msg/s\n".
        "✅ <b>Finish</b>";

    try {
        $api->messages->editMessage([
            'peer' => $chatId,
            'id' => $statusId,
            'message' => $finalText,
            'parse_mode' => 'HTML'
        ]);
    } catch (\Throwable) {}

            try { \Amp\File\write(__DIR__."/data/LastBrodDATA.txt", $finalText); } catch (\Throwable) {}

        foreach ($state['lastMessageIds'] as $peer => $id) {
            $dir = __DIR__."/data/$peer";
            if (!is_dir($dir)) mkdir($dir, 0777, true);
            try { \Amp\File\write("$dir/lastBroadcast.txt", (string)$id); } catch (\Throwable) {}
        }

        $this->currentBroadcastState = &$state;

        return $state;
    }

    /**
     * Delete last broadcast message for all users
     */
    public function deleteLastBroadcastForAll(array $allUsers, $chatId, int $concurrency = 20): array {
    $api = $this->api;
    $total = count($allUsers);
    $state = [
        'deleted' => 0,
        'failed' => 0,
        'flood' => 0,
        'queue' => new \SplQueue(),
        'done' => false,
        'startedAt' => microtime(true),
    ];

    foreach ($allUsers as $peer) {
        $state['queue']->enqueue(['peer' => $peer, 'attempts' => 0]);
    }

    $status = $api->messages->sendMessage([
        'peer' => $chatId,
        'message' => "⌛ Deleting last broadcast...",
        'parse_mode' => 'HTML'
    ]);
    $statusId = $api->extractMessageId($status);

    \Amp\async(function () use ($api, $chatId, $statusId, &$state, $total) {
        $lastText = '';
        while (!$state['done']) {
            $processed = $state['deleted'] + $state['failed'];
            $pending   = $total - $processed;
            $elapsed   = microtime(true) - $state['startedAt'];
            $tps       = $elapsed > 0 ? round($state['deleted'] / $elapsed, 2) : 0;

            $text =
                "<b>📊 Deleting Last Broadcast</b>\n\n".
                "<code>".$this->progressBar($processed, $total)."</code>\n\n".
                "✅ Deleted: {$state['deleted']} / $total\n".
                "❌ Failed: {$state['failed']}\n".
                "⏳ Pending: $pending\n".
                "⚡ TPS: {$tps} msg/s";

            if ($text !== $lastText) {
                try {
                    $api->messages->editMessage([
                        'peer' => $chatId,
                        'id' => $statusId,
                        'message' => $text,
                        'parse_mode' => 'HTML'
                    ]);
                    $lastText = $text;
                } catch (\Throwable) {}
            }
            $api->sleep(1);
        }
    });

    for ($i = 0; $i < $concurrency; $i++) {
        \Amp\async(function () use ($api, &$state) {
            while (!$state['queue']->isEmpty()) {
                $job = $state['queue']->dequeue();
                $peer = $job['peer'];
                $attempts = $job['attempts'];

                $file = __DIR__."/data/$peer/lastBroadcast.txt";
                if (!file_exists($file)) {
                    $state['failed']++;
                    continue;
                }

                try {
                    $lastMessageId = (int) \Amp\File\read($file);

                    $api->messages->deleteMessages([
                        'id' => [$lastMessageId],
                        'revoke' => true,
                        'peer' => $peer
                    ]);

                    unlink($file);
                    $state['deleted']++;

                } catch (\danog\MadelineProto\RPCErrorException $e) {
                    if (str_contains($e->getMessage(), 'FLOOD_WAIT')) {
                        $state['flood']++;
                        preg_match('/FLOOD_WAIT_(\d+)/', $e->getMessage(), $m);
                        $api->sleep((int)($m[1] ?? 5));
                        $state['queue']->enqueue(['peer'=>$peer,'attempts'=>$attempts]);
                        continue;
                    }

                    if ($attempts >= 3) {
                        $state['failed']++;
                    } else {
                        $state['queue']->enqueue(['peer'=>$peer,'attempts'=>$attempts + 1]);
                        $api->sleep(0.5);
                    }

                } catch (\Throwable) {
                    $state['failed']++;
                }
            }
        });
    }

    while (($state['deleted'] + $state['failed']) < $total) {
        $api->sleep(1);
    }

    $state['done'] = true;

    $elapsed = microtime(true) - $state['startedAt'];
    $tps = $elapsed > 0 ? round($state['deleted'] / $elapsed, 2) : 0;
    $finalText =
        "<b>📊 Deleting Last Broadcast</b>\n\n".
        "<code>".$this->progressBar($state['deleted'] + $state['failed'], $total)."</code>\n\n".
        "✅ Deleted: {$state['deleted']} / $total\n".
        "❌ Failed: {$state['failed']}\n".
        "✅ <b>Finish</b>";

    try {
        $api->messages->editMessage([
            'peer' => $chatId,
            'id' => $statusId,
            'message' => $finalText,
            'parse_mode' => 'HTML'
        ]);
    } catch (\Throwable) {}

    return $state;
}

    /**
     * Unpin all messages for all users
     */
    public function unpinAllMessagesForAll(array $allUsers, $chatId, string $filterType = 'users', int $concurrency = 20): array {
    $api = $this->api;

        $targets = [];
        foreach ($allUsers as $peer) {
            try {
                $info = $api->getInfo($peer);
                $type = $info['type'] ?? 'user';

$allowedTypesByFilter = [
    'all' => ['user', 'bot', 'chat', 'supergroup', 'channel'],
    'users' => ['user', 'bot'],
    'groups' => ['chat', 'supergroup'],
    'channels' => ['channel'],
];

$allowed = $allowedTypesByFilter[$filterType] ?? ['user', 'bot'];

if (in_array($type, $allowed, true)) {
    $targets[] = (string) $peer;
}
				
            } catch (\Throwable) { continue; }
        }

    $total = count($targets);

    $state = [
        'unpin' => 0,
        'failed' => 0,
        'flood' => 0,
        'queue' => new \SplQueue(),
        'done' => false,
        'startedAt' => microtime(true),
    ];

    foreach ($targets as $peer) {
        $state['queue']->enqueue([
            'peer' => $peer,
            'attempts' => 0
        ]);
    }

    $status = $api->messages->sendMessage([
        'peer' => $chatId,
        'message' => "📌⌛ Starting unpin for all subscribers...",
        'parse_mode' => 'HTML'
    ]);
    $statusId = $api->extractMessageId($status);

    \Amp\async(function () use ($api, $chatId, $statusId, &$state, $total) {
        $lastText = '';
        while (!$state['done']) {
            $processed = $state['unpin'] + $state['failed'];
            $pending   = $total - $processed;
            $elapsed   = microtime(true) - $state['startedAt'];
            $tps       = $elapsed > 0 ? round($state['unpin'] / $elapsed, 2) : 0;

            $text =
                "<b>📌 Unpinning Messages</b>\n\n".
                "<code>".$this->progressBar($processed, $total)."</code>\n\n".
                "📤 Unpinned: {$state['unpin']} / $total\n".
                "❌ Failed: {$state['failed']}\n".
                "⚠ FLOOD_WAIT: {$state['flood']}\n".
                "⏳ Pending: $pending\n".
                "⚡ TPS: {$tps} msg/s";

            if ($text !== $lastText) {
                try {
                    $api->messages->editMessage([
                        'peer' => $chatId,
                        'id' => $statusId,
                        'message' => $text,
                        'parse_mode' => 'HTML'
                    ]);
                    $lastText = $text;
                } catch (\Throwable) {}
            }

            $api->sleep(1);
        }
    });

    for ($i = 0; $i < $concurrency; $i++) {
        \Amp\async(function () use ($api, &$state) {
            while (!$state['queue']->isEmpty()) {
                $job = $state['queue']->dequeue();
                $peer = $job['peer'];
                $attempts = $job['attempts'];

                try {
                    $api->messages->unpinAllMessages([
                        'peer' => $peer
                    ]);

                    $state['unpin']++;

                } catch (\danog\MadelineProto\RPCErrorException $e) {

                    if (str_contains($e->getMessage(), 'FLOOD_WAIT')) {
                        $state['flood']++;
                        preg_match('/FLOOD_WAIT_(\d+)/', $e->getMessage(), $m);
                        $api->sleep((int)($m[1] ?? 5));
                        $state['queue']->enqueue([
                            'peer' => $peer,
                            'attempts' => $attempts
                        ]);
                        continue;
                    }

                    if ($attempts >= 3) {
                        $state['failed']++;
                    } else {
                        $state['queue']->enqueue([
                            'peer' => $peer,
                            'attempts' => $attempts + 1
                        ]);
                        $api->sleep(0.5);
                    }

                } catch (\Throwable) {
                    $state['failed']++;
                }
            }
        });
    }

    while (($state['unpin'] + $state['failed']) < $total) {
        $api->sleep(1);
    }

    $state['done'] = true;

    $finalText =
        "<b>📌 Unpinning Messages</b>\n\n".
        "<code>".$this->progressBar($state['unpin'] + $state['failed'], $total)."</code>\n\n".
        "📤 Unpinned: {$state['unpin']} / $total\n".
        "❌ Failed: {$state['failed']}\n".
        "✅ <b>Finish</b>";

    try {
        $api->messages->editMessage([
            'peer' => $chatId,
            'id' => $statusId,
            'message' => $finalText,
            'parse_mode' => 'HTML'
        ]);
    } catch (\Throwable) {}

    return $state;
}

    private function progressBar(int $current, int $total): string {
        $len = 20;
        $filled = (int) round($current / max($total,1) * $len);
        return str_repeat('█',$filled).str_repeat('░',$len-$filled).' '.round(($current/$total)*100).'%';
    }

    /**
     * Pause running broadcast
     */
    public function pause(): void {
        if ($this->currentBroadcastState) {
            $this->currentBroadcastState['paused'] = true;
        }
    }

    /**
     * Resume running broadcast
     */
    public function resume(): void {
        if ($this->currentBroadcastState) {
            $this->currentBroadcastState['paused'] = false;
        }
    }

    /**
     * cancel running broadcast
     */
    public function cancel(): void {
        if ($this->currentBroadcastState) {
            $this->currentBroadcastState['cancel'] = true;
        }
    }

/**
 * Check if broadcast is paused
 */
public function isPaused(): bool {
    return $this->currentBroadcastState['paused'] ?? false;
}

/**
 * Check if broadcast is cancelled
 */
public function isCancelled(): bool {
    return $this->currentBroadcastState['cancel'] ?? false;
}

/**
 * Check if there is a last broadcast message saved for deletion
 */
public function hasLastBroadcast(): bool {
    foreach (glob(__DIR__."/data/*/lastBroadcast.txt") as $file) {
        if (file_exists($file) && trim(\Amp\File\read($file))) {
            return true;
        }
    }
    return false;
}

/**
 * Get current broadcast progress
 */
public function progress(): ?array {
    if (!$this->currentBroadcastState) return null;

    $state = $this->currentBroadcastState;
    $processed = $state['sent'] + $state['failed'];
    $pending = ($state['queue'] ? $state['queue']->count() : 0);

    return [
        'sent' => $state['sent'],
        'failed' => $state['failed'],
        'flood' => $state['flood'] ?? 0,
        'pending' => $pending,
        'done' => $state['done'] ?? false,
        'paused' => $state['paused'] ?? false,
        'cancelled' => $state['cancel'] ?? false,
    ];
}

}
