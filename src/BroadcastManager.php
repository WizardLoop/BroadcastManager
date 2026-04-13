<?php

declare(strict_types=1);

/**
 * Broadcast Manager Tool
 *
 * @author    - WizardLoop <wizardloop.com>
 * @copyright - WizardLoop <wizardloop.com>
 * @license   - https://opensource.org/licenses/AGPL-3.0 AGPLv3
 *
 */

namespace BroadcastTool;

use Amp\File;
use danog\MadelineProto\API;
use danog\MadelineProto\RPCErrorException;
use SplQueue;

class BroadcastManager
{
    private API $api;
    private ?array $currentBroadcastState = null;
    private static string $dataDir = '';

    public function __construct(API $api)
    {
        $this->api = $api;
    }

    /**
     * Set data dir
    */
    public static function setDataDir(string $path): void {
        self::$dataDir = rtrim($path, '/');
    }

    /**
     * Get data dir
    */
    private static function getDataDir(): string {
        if (!self::$dataDir) {
            self::$dataDir = __DIR__ . '/../data';
        }
        return self::$dataDir;
    }

    /**
     * Send broadcast.
     *
     * @return integer ID that can be used
     */
    public function broadcastWithProgress(
        array $allUsers,
        array $messages,
        $chatId = null,
        bool $pin = false,
        int $concurrency = 20
    ): string {
    $api = $this->api;

    $statusId = null;
    if ($chatId) {
    try {
    /* ===== INIT STATUS ===== */
        $status = $api->messages->sendMessage([
        'peer' => $chatId,
        'message' => '⌛ GATHERING PEERS...',
        'parse_mode' => 'HTML'
    ]);
    $statusId = $api->extractMessageId($status);
    } catch (\Throwable) { }
    }

    $total = count($allUsers);

    $broadcastId = bin2hex(random_bytes(8));

    /* ===== STATE ===== */
    $state = [
        'type' => 'send',
        'sent' => 0,
        'failed' => 0,
        'queue' => new \SplQueue(),
        'inFlight' => [],
        'lastMessageIds' => [],
        'paused' => false,
        'cancel' => false,
        'done' => false,
        'startedAt' => microtime(true),
    ];

    $this->currentBroadcastState[$broadcastId] = $state;

    foreach ($allUsers as $peer) {
        $state['queue']->enqueue([
            'peer' => $peer,
            'attempts' => 0,
            'startedAt' => null,
            'availableAt' => 0
        ]);
    }

    /* ===== PROGRESS LOOP ===== */
    \Amp\async(function () use ($api, $chatId, $statusId, &$state, $total) {
        $last = '';
        while (!$state['done']) {
            $processed = $state['sent'] + $state['failed'];
            $pending = max(0, $total - $processed);
            $elapsed = microtime(true) - $state['startedAt'];
            $tps = $elapsed > 0 ? round($state['sent'] / $elapsed, 2) : 0;

            $text =
                "<b>📊 Broadcast Progress</b>\n\n".
                "<code>".$this->progressBar($processed, max(1,$total))."</code>\n\n".
                "📨 Sent: {$state['sent']} / $total\n".
                "❌ Failed: {$state['failed']}\n".
                "⏳ Pending: $pending\n".
                "⚡ TPS: {$tps} msg/s".
                ($state['paused'] ? "\n⏸ <b>Paused</b>" : '').
                ($state['cancel'] ? "\n🛑 <b>Cancelled</b>" : '');

            if ($chatId && $statusId && $text !== $last) {
                try {
                    $api->messages->editMessage([
                        'peer' => $chatId,
                        'id' => $statusId,
                        'message' => $text,
                        'parse_mode' => 'HTML'
                    ]);
                    $last = $text;
                } catch (\Throwable) {}
            }
            $api->sleep(1);
        }
    });

    /* ===== WATCHDOG ===== */
    \Amp\async(function () use (&$state) {
        while (!$state['done']) {
            foreach ($state['inFlight'] as $peer => $job) {
                if ($job['startedAt'] && microtime(true) - $job['startedAt'] > 60) {
                    unset($state['inFlight'][$peer]);
                    $job['attempts']++;
                    $job['startedAt'] = null;
                    if ($job['attempts'] >= 3) $state['failed']++;
                    else $state['queue']->enqueue($job);
                }
            }
            \danog\MadelineProto\Tools::sleep(5);
        }
    });

    /* ===== WORKERS ===== */
    for ($i=0; $i<$concurrency; $i++) {
        \Amp\async(function () use ($api, &$state, $messages, $pin) {
            while (!$state['cancel']) {

                if ($state['queue']->isEmpty()) {
                    $api->sleep(1);
                    continue;
                }

                $job = $state['queue']->dequeue();
                if ($job['availableAt'] > microtime(true)) {
                    $state['queue']->enqueue($job);
                    $api->sleep(0.5);
                    continue;
                }

                while ($state['paused']) $api->sleep(1);

                $peer = $job['peer'];
                $job['startedAt'] = microtime(true);
                $state['inFlight'][$peer] = $job;

                try {
                    $lastMessageId = null;
                    $albumMessages = [];

                    foreach ($messages as $m) {
                        if (isset($m['albumFile']) && file_exists($m['albumFile'])) {
                            $albumMessages = json_decode(\Amp\File\read($m['albumFile']), true) ?? [];
                        }
                    }

                    if ($albumMessages) {
                        foreach (array_chunk($albumMessages,10) as $chunk) {
                            $multi = [];
                            foreach ($chunk as $item) {
                                $media = $item['media']['type']==='photo'
                                    ? ['_' => 'inputMediaPhoto','id'=>$item['media']['botApiFileId']]
                                    : ['_' => 'inputMediaDocument','id'=>$item['media']['botApiFileId']];
                                $multi[] = [
                                    '_' => 'inputSingleMedia',
                                    'media' => $media,
                                    'message' => $item['caption'] ?? '',
                                    'entities' => $item['entities'] ?? []
                                ];
                            }
                            foreach ($api->messages->sendMultiMedia(['peer' => $peer, 'multi_media' => $multi]) as $u) {

                                $lastMessageId = $api->extractMessageId($u);
                            }
                        }
                    } else {
                        foreach ($messages as $m) {
                            $method = isset($m['media']) ? 'sendMedia' : 'sendMessage';
                            $payload = $m + ['peer'=>$peer,'floodWaitLimit'=>172800];
                            if (isset($m['buttons'])) $payload['reply_markup']=$m['buttons'];
                            $res = $api->messages->$method($payload);
                            $lastMessageId = $api->extractMessageId($res);
                        }
                    }

                    if ($pin && $lastMessageId) {
                        $api->messages->updatePinnedMessage([
                            'peer'=>$peer,'id'=>$lastMessageId,'unpin'=>false
                        ]);
                    }

                    unset($state['inFlight'][$peer]);
                    $state['sent']++;
                    $state['lastMessageIds'][$peer]=(string)$lastMessageId;

                } catch (\danog\MadelineProto\RPCErrorException $e) {
                    unset($state['inFlight'][$peer]);
					            
	            if ($e->rpc === 'INPUT_USER_DEACTIVATED' || 
				    $e->rpc === 'USER_IS_BOT' || 
					$e->rpc === 'CHAT_WRITE_FORBIDDEN' || 
					$e->rpc === 'USER_IS_BLOCKED' ||
					$e->rpc === 'PEER_ID_INVALID') {
				    $state['failed']++;
					continue;
                }

                    if (preg_match('/FLOOD_WAIT_(\d+)/',$e->getMessage(),$m)) {
                        $job['attempts']++;
                        $job['availableAt']=microtime(true)+(int)$m[1];
                        $job['startedAt']=null;
                        if ($job['attempts']>=3) $state['failed']++;
                        else $state['queue']->enqueue($job);
                        continue;
                    }
                    if (++$job['attempts']>=3) $state['failed']++;
                    else { $job['startedAt']=null; $state['queue']->enqueue($job); }

                } catch (\Throwable) {
                    unset($state['inFlight'][$peer]);
                    $state['failed']++;
                }

                $api->sleep(0.25);
            }
        });
    }

    /* ===== WAIT FOR REAL FINISH ===== */
    while (!$state['cancel']) {
        if ($state['queue']->isEmpty() && empty($state['inFlight'])) break;
        $api->sleep(1);
    }

    $state['done'] = true;

    /* ===== FINAL UPDATE + SAVE ===== */
    $processed = $state['sent'] + $state['failed'];
    $elapsed = microtime(true) - $state['startedAt'];
    $tps = $elapsed>0 ? round($state['sent']/$elapsed,2):0;

    $finalText =
        "<b>📊 Broadcast Progress</b>\n\n".
        "<code>".$this->progressBar($processed,max(1,$total))."</code>\n\n".
        "📨 Sent: {$state['sent']} / $total\n".
        "❌ Failed: {$state['failed']}\n".
        "⚡ TPS: {$tps} msg/s\n".
        ($state['cancel'] ? "🛑 <b>Cancelled</b>" : "✅ <b>Finished</b>");

    if ($chatId) {
        try { $api->messages->editMessage(['peer'=>$chatId,'id'=>$statusId,'message'=>$finalText,'parse_mode'=>'HTML']); } catch (\Throwable) {}
    }

    $dir1 = self::getDataDir();
    try { if(!is_dir($dir1))@mkdir($dir1,0777,true); } catch (\Throwable) {}
    try { \Amp\File\write("$dir1/LastBrodDATA.txt",$finalText); } catch (\Throwable) {}

    foreach ($state['lastMessageIds'] as $peer=>$id) {
        $dir = self::getDataDir() . "/$peer";
        try { if(!is_dir($dir))@mkdir($dir,0777,true); } catch (\Throwable) {}
		try {
        $fh = \Amp\File\openFile("$dir/messages.txt", "a");
        $fh->write((string)$id . "\n");
        $fh->close();
        } catch (\Throwable) {}
        try{\Amp\File\write("$dir/lastBroadcast.txt",$id);}catch(\Throwable){}
    }

    $this->currentBroadcastState[$broadcastId] = $state;
    return $broadcastId;
}

    /**
     * Deletes the last broadcast message for all users.
     *
     * @return integer ID that can be used
     */
    public function deleteLastBroadcastForAll(
        array $allUsers,
        $chatId = null,
        int $concurrency = 20
    ): string {
    $api = $this->api;
    $total = count($allUsers);

    $broadcastId = bin2hex(random_bytes(8));

    /* ===== STATE ===== */
    $state = [
        'type' => 'deletelast',
        'deleted' => 0,
        'failed' => 0,
        'flood' => 0,
        'queue' => new \SplQueue(),
        'inFlight' => [],
        'done' => false,
        'cancel' => false,
        'startedAt' => microtime(true),
    ];

    $this->currentBroadcastState[$broadcastId] = $state;

    foreach ($allUsers as $peer) {
        $state['queue']->enqueue([
            'peer' => (string)$peer,
            'attempts' => 0,
            'startedAt' => null,
            'availableAt' => 0
        ]);
    }

    $statusId = null;
    if ($chatId) {
    try {
    /* ===== STATUS MESSAGE ===== */
    $status = $api->messages->sendMessage([
        'peer' => $chatId,
        'message' => "⌛ Deleting last broadcast...",
        'parse_mode' => 'HTML'
    ]);
    $statusId = $api->extractMessageId($status);
    } catch (\Throwable) { }
	}

    /* ===== PROGRESS LOOP ===== */
    \Amp\async(function () use ($api, $chatId, $statusId, &$state, $total) {
        $last = '';
        while (!$state['done']) {
            $processed = $state['deleted'] + $state['failed'];
            $pending = max(0, $total - $processed);
            $elapsed = microtime(true) - $state['startedAt'];
            $tps = $elapsed > 0 ? round($state['deleted'] / $elapsed, 2) : 0;

            $text =
                "<b>📊 Deleting Last Broadcast</b>\n\n".
                "<code>".$this->progressBar($processed, max(1,$total))."</code>\n\n".
                "✅ Deleted: {$state['deleted']} / $total\n".
                "❌ Failed: {$state['failed']}\n".
                "⏳ Pending: $pending\n".
                "⚡ TPS: {$tps} msg/s".
                ($state['cancel'] ? "\n🛑 <b>Cancelled</b>" : '');

            if ($chatId && $statusId && $text !== $last) {
                try {
                    $api->messages->editMessage([
                        'peer' => $chatId,
                        'id' => $statusId,
                        'message' => $text,
                        'parse_mode' => 'HTML'
                    ]);
                    $last = $text;
                } catch (\Throwable) {}
            }

            $api->sleep(1);
        }
    });

    /* ===== WATCHDOG ===== */
    \Amp\async(function () use (&$state) {
        while (!$state['done']) {
            foreach ($state['inFlight'] as $peer => $job) {
                if ($job['startedAt'] && microtime(true) - $job['startedAt'] > 60) {
                    unset($state['inFlight'][$peer]);
                    $job['attempts']++;
                    $job['startedAt'] = null;

                    if ($job['attempts'] >= 3) {
                        $state['failed']++;
                    } else {
                        $state['queue']->enqueue($job);
                    }
                }
            }
            \danog\MadelineProto\Tools::sleep(5);
        }
    });

    /* ===== WORKERS ===== */
    for ($i = 0; $i < $concurrency; $i++) {
        \Amp\async(function () use ($api, &$state) {
            while (!$state['cancel']) {

                if ($state['queue']->isEmpty()) {
                    $api->sleep(1);
                    continue;
                }

                $job = $state['queue']->dequeue();

                if ($job['availableAt'] > microtime(true)) {
                    $state['queue']->enqueue($job);
                    $api->sleep(0.5);
                    continue;
                }

                $peer = $job['peer'];
                $job['startedAt'] = microtime(true);
                $state['inFlight'][$peer] = $job;

                try {
                    $file = self::getDataDir() ."/$peer/lastBroadcast.txt";
                    if (!file_exists($file)) {
						$state['failed']++;
                        unset($state['inFlight'][$peer]);
                        continue;
                    }

                    $lastMessageId = (int)\Amp\File\read($file);

                    $api->messages->deleteMessages([
                        'peer' => $peer,
                        'id' => [$lastMessageId],
                        'revoke' => true
                    ]);

                    @unlink($file);

                    $state['deleted']++;
                    unset($state['inFlight'][$peer]);

                } catch (\danog\MadelineProto\RPCErrorException $e) {
                    unset($state['inFlight'][$peer]);

                    if (preg_match('/FLOOD_WAIT_(\d+)/', $e->getMessage(), $m)) {
                        $state['flood']++;
                        $job['attempts']++;
                        $job['availableAt'] = microtime(true) + (int)$m[1];
                        $job['startedAt'] = null;

                        if ($job['attempts'] >= 3) {
                            $state['failed']++;
                        } else {
                            $state['queue']->enqueue($job);
                        }
                        continue;
                    }

                    if (++$job['attempts'] >= 3) {
                        $state['failed']++;
                    } else {
                        $job['startedAt'] = null;
                        $state['queue']->enqueue($job);
                    }

                } catch (\Throwable) {
                    $state['failed']++;
                    unset($state['inFlight'][$peer]);
                }

                $api->sleep(0.25);
            }
        });
    }

    /* ===== WAIT FOR REAL FINISH ===== */
    while (!$state['cancel']) {
        if ($state['queue']->isEmpty() && empty($state['inFlight'])) {
            break;
        }
        $api->sleep(1);
    }

    $state['done'] = true;

    /* ===== FINAL UPDATE ===== */
    $processed = $state['deleted'] + $state['failed'];
    $elapsed = microtime(true) - $state['startedAt'];
    $tps = $elapsed > 0 ? round($state['deleted'] / $elapsed, 2) : 0;

    $finalText =
        "<b>📊 Deleting Last Broadcast</b>\n\n".
        "<code>".$this->progressBar($processed, max(1,$total))."</code>\n\n".
        "✅ Deleted: {$state['deleted']} / $total\n".
        "❌ Failed: {$state['failed']}\n".
        ($state['cancel'] ? "🛑 <b>Cancelled</b>" : "✅ <b>Finished</b>");

    if ($chatId) { 
    try {
        $api->messages->editMessage([
            'peer' => $chatId,
            'id' => $statusId,
            'message' => $finalText,
            'parse_mode' => 'HTML'
        ]);
    } catch (\Throwable) {}
    }

    $this->currentBroadcastState[$broadcastId] = $state;
    return $broadcastId;
}

    /**
     * Deletes all broadcast message for all users.
     *
     * @return integer ID that can be used
     */
    public function deleteAllBroadcastsForAll(
        array $allUsers,
        $chatId = null,
        int $concurrency = 20
    ): string {
    $api = $this->api;
    $total = count($allUsers);

    $broadcastId = bin2hex(random_bytes(8));

    $state = [
        'type' => 'deleteall',
        'deleted' => 0,
        'failed' => 0,
        'flood' => 0,
        'queue' => new \SplQueue(),
        'inFlight' => [],
        'done' => false,
        'cancel' => false,
        'startedAt' => microtime(true),
    ];

    $this->currentBroadcastState[$broadcastId] = $state;

    foreach ($allUsers as $peer) {
        $state['queue']->enqueue([
            'peer' => (string)$peer,
            'attempts' => 0,
            'startedAt' => null,
            'availableAt' => 0
        ]);
    }

    $statusId = null;
    if ($chatId) {
    try {
    $status = $api->messages->sendMessage([
        'peer' => $chatId,
        'message' => "⌛ Deleting all broadcasts...",
        'parse_mode' => 'HTML'
    ]);
    $statusId = $api->extractMessageId($status);
    } catch (\Throwable) { }
	}

    \Amp\async(function () use (&$state) {
        while (!$state['done']) {
            foreach ($state['inFlight'] as $peer => $job) {
                if ($job['startedAt'] && microtime(true) - $job['startedAt'] > 60) {
                    unset($state['inFlight'][$peer]);
                    $job['attempts']++;
                    $job['startedAt'] = null;

                    if ($job['attempts'] >= 3) {
                        $state['failed']++;
                    } else {
                        $state['queue']->enqueue($job);
                    }
                }
            }
            \danog\MadelineProto\Tools::sleep(5);
        }
    });

    for ($i = 0; $i < $concurrency; $i++) {
        \Amp\async(function () use ($api, &$state, $chatId, $statusId, $total) {
            while (!$state['cancel']) {
                if ($state['queue']->isEmpty()) {
                    $api->sleep(1);
                    continue;
                }

                $job = $state['queue']->dequeue();

                if ($job['availableAt'] > microtime(true)) {
                    $state['queue']->enqueue($job);
                    $api->sleep(0.5);
                    continue;
                }

                $peer = $job['peer'];
                $job['startedAt'] = microtime(true);
                $state['inFlight'][$peer] = $job;

                $userDeleted = false;

                try {
                    $file = self::getDataDir() ."/$peer/messages.txt";
                    if (!file_exists($file)) {
                        $state['failed']++;
                        unset($state['inFlight'][$peer]);
                        continue;
                    }

                    $msgIds = array_filter(
                        array_map('intval', explode("\n", trim(\Amp\File\read($file))))
                    );

                    foreach ($msgIds as $mid) {
                        if ($mid <= 0) continue;

                        try {
                            $api->messages->deleteMessages([
                                'peer' => $peer,
                                'id' => [$mid],
                                'revoke' => true
                            ]);
                            $userDeleted = true;
                        } catch (\danog\MadelineProto\RPCErrorException $e) {
                            $msg = $e->getMessage();

                            if (preg_match('/FLOOD_WAIT_(\d+)/', $msg, $m)) {
                                $state['flood']++;
                                unset($state['inFlight'][$peer]);

                                $job['attempts']++;
                                $job['availableAt'] = microtime(true) + (int)$m[1];
                                $job['startedAt'] = null;

                                if ($job['attempts'] >= 3) $state['failed']++;
                                else $state['queue']->enqueue($job);
                                continue 2;
                            }

                            if (str_contains($msg, 'USER_IS_BLOCKED') || str_contains($msg, 'PEER_ID_INVALID')) {
                                continue;
                            }

                            throw $e;
                        }
                    }

                    if ($userDeleted) $state['deleted']++;
                    else $state['failed']++;

                    $file2 = self::getDataDir() ."/$peer/lastBroadcast.txt";
                    try { @unlink($file2); } catch (\Throwable) { }
                    try { @unlink($file); } catch (\Throwable) { }
                    unset($state['inFlight'][$peer]);

                    $processed = $state['deleted'] + $state['failed'];
                    $pending = max(0, $total - $processed);
                    $elapsed = microtime(true) - $state['startedAt'];
                    $tps = $elapsed > 0 ? round($state['deleted'] / $elapsed, 2) : 0;

                    $text =
                        "<b>📊 Deleting All Broadcasts</b>\n\n".
                        "<code>".$this->progressBar($processed, max(1,$total))."</code>\n\n".
                        "✅ Deleted: {$state['deleted']} / $total\n".
                        "❌ Failed: {$state['failed']}\n".
                        "⏳ Pending: $pending\n".
                        "⚡ TPS: {$tps} msg/s";

                   if ($chatId) {
                    try {
                        $api->messages->editMessage([
                            'peer' => $chatId,
                            'id' => $statusId,
                            'message' => $text,
                            'parse_mode' => 'HTML'
                        ]);
                    } catch (\Throwable) {}
                   }

                } catch (\Throwable) {
                    unset($state['inFlight'][$peer]);
                    if (++$job['attempts'] >= 3) $state['failed']++;
                    else { $job['startedAt'] = null; $state['queue']->enqueue($job); }
                }

                $api->sleep(0.25);
            }
        });
    }

    while (!$state['cancel']) {
        if ($state['queue']->isEmpty() && empty($state['inFlight'])) break;
        $api->sleep(1);
    }

    $state['done'] = true;

    $processed = $state['deleted'] + $state['failed'];
    $elapsed = microtime(true) - $state['startedAt'];
    $tps = $elapsed > 0 ? round($state['deleted'] / $elapsed, 2) : 0;

    $finalText =
        "<b>📊 Deleting All Broadcasts</b>\n\n".
        "<code>".$this->progressBar($processed, max(1,$total))."</code>\n\n".
        "✅ Deleted: {$state['deleted']} / $total\n".
        "❌ Failed: {$state['failed']}\n".
        ($state['cancel'] ? "🛑 <b>Cancelled</b>" : "✅ <b>Finished</b>");

    if ($chatId) {
    try {
        $api->messages->editMessage([
            'peer' => $chatId,
            'id' => $statusId,
            'message' => $finalText,
            'parse_mode' => 'HTML'
        ]);
    } catch (\Throwable) {}
	}

    $this->currentBroadcastState[$broadcastId] = $state;
    return $broadcastId;
}

    /**
     * Unpin all messages for all users
     *
     * @return integer ID that can be used
     */
    public function unpinAllMessagesForAll(
        array $allUsers,
        $chatId = null,
        int $concurrency = 20
    ): string {
    $api = $this->api;

    $statusId = null;
    if ($chatId) {
    try {
    /* ===== STATUS MESSAGE ===== */
    $status = $api->messages->sendMessage([
        'peer' => $chatId,
        'message' => '⌛ GATHERING PEERS...',
        'parse_mode' => 'HTML'
    ]);
    $statusId = $api->extractMessageId($status);
    } catch (\Throwable) { }
	}

    $total = count($allUsers);

    $broadcastId = bin2hex(random_bytes(8));

    /* ===== STATE ===== */
    $state = [
        'type' => 'unpin',
        'unpin' => 0,
        'failed' => 0,
        'flood' => 0,
        'queue' => new \SplQueue(),
        'inFlight' => [],
        'done' => false,
        'cancel' => false,
        'startedAt' => microtime(true),
    ];

    $this->currentBroadcastState[$broadcastId] = $state;

    foreach ($allUsers as $peer) {
        $state['queue']->enqueue([
            'peer' => $peer,
            'attempts' => 0,
            'startedAt' => null,
            'availableAt' => 0
        ]);
    }

    if ($chatId) {
    try {
    $api->messages->editMessage([
        'peer' => $chatId,
        'id' => $statusId,
        'message' => "📌⌛ Starting unpin for all subscribers...",
        'parse_mode' => 'HTML'
    ]);
    } catch (\Throwable) { }
	}

    /* ===== PROGRESS LOOP ===== */
    \Amp\async(function () use ($api, $chatId, $statusId, &$state, $total) {
        $last = '';
        while (!$state['done']) {
            $processed = $state['unpin'] + $state['failed'];
            $pending   = max(0, $total - $processed);
            $elapsed   = microtime(true) - $state['startedAt'];
            $tps       = $elapsed > 0 ? round($state['unpin'] / $elapsed, 2) : 0;

            $text =
                "<b>📌 Unpinning Messages</b>\n\n".
                "<code>".$this->progressBar($processed, max(1,$total))."</code>\n\n".
                "📤 Unpinned: {$state['unpin']} / $total\n".
                "❌ Failed: {$state['failed']}\n".
                "⚠ FLOOD_WAIT: {$state['flood']}\n".
                "⏳ Pending: $pending\n".
                "⚡ TPS: {$tps} msg/s".
                ($state['cancel'] ? "\n🛑 <b>Cancelled</b>" : '');

            if ($chatId && $statusId && $text !== $last) {
                try {
                    $api->messages->editMessage([
                        'peer' => $chatId,
                        'id' => $statusId,
                        'message' => $text,
                        'parse_mode' => 'HTML'
                    ]);
                    $last = $text;
                } catch (\Throwable) {}
            }

            $api->sleep(1);
        }
    });

    /* ===== WATCHDOG ===== */
    \Amp\async(function () use (&$state) {
        while (!$state['done']) {
            foreach ($state['inFlight'] as $peer => $job) {
                if ($job['startedAt'] && microtime(true) - $job['startedAt'] > 60) {
                    unset($state['inFlight'][$peer]);
                    $job['attempts']++;
                    $job['startedAt'] = null;

                    if ($job['attempts'] >= 3) $state['failed']++;
                    else $state['queue']->enqueue($job);
                }
            }
            \danog\MadelineProto\Tools::sleep(5);
        }
    });

    /* ===== WORKERS ===== */
    for ($i = 0; $i < $concurrency; $i++) {
        \Amp\async(function () use ($api, &$state) {
            while (!$state['cancel']) {
                if ($state['queue']->isEmpty()) {
                    $api->sleep(1);
                    continue;
                }

                $job = $state['queue']->dequeue();

                if ($job['availableAt'] > microtime(true)) {
                    $state['queue']->enqueue($job);
                    $api->sleep(0.5);
                    continue;
                }

                $peer = $job['peer'];
                $job['startedAt'] = microtime(true);
                $state['inFlight'][$peer] = $job;

                try {
                    $api->messages->unpinAllMessages([
                        'peer' => $peer
                    ]);
                    unset($state['inFlight'][$peer]);
                    $state['unpin']++;

                } catch (\danog\MadelineProto\RPCErrorException $e) {
                    unset($state['inFlight'][$peer]);
                    $msg = $e->getMessage();

                    if (preg_match('/FLOOD_WAIT_(\d+)/', $msg, $m)) {
                        $state['flood']++;
                        $job['attempts']++;
                        $job['availableAt'] = microtime(true) + (int)($m[1] ?? 5);
                        $job['startedAt'] = null;

                        if ($job['attempts'] >= 3) $state['failed']++;
                        else $state['queue']->enqueue($job);
                        continue;
                    }

                    if ($job['attempts']++ >= 3) $state['failed']++;
                    else {
                        $job['startedAt'] = null;
                        $state['queue']->enqueue($job);
                        $api->sleep(0.5);
                    }

                } catch (\Throwable) {
                    unset($state['inFlight'][$peer]);
                    $state['failed']++;
                }

                $api->sleep(0.25);
            }
        });
    }

    /* ===== WAIT FOR FINISH ===== */
    while (!$state['cancel']) {
        if ($state['queue']->isEmpty() && empty($state['inFlight'])) break;
        $api->sleep(1);
    }

    $state['done'] = true;

    /* ===== FINAL UPDATE ===== */
    $processed = $state['unpin'] + $state['failed'];
    $finalText =
        "<b>📌 Unpinning Messages</b>\n\n".
        "<code>".$this->progressBar($processed, max(1,$total))."</code>\n\n".
        "📤 Unpinned: {$state['unpin']} / $total\n".
        "❌ Failed: {$state['failed']}\n".
        ($state['cancel'] ? "🛑 <b>Cancelled</b>" : "✅ <b>Finished</b>");

    if ($chatId) {
    try {
        $api->messages->editMessage([
            'peer' => $chatId,
            'id' => $statusId,
            'message' => $finalText,
            'parse_mode' => 'HTML'
        ]);
    } catch (\Throwable) {}
    }

    $this->currentBroadcastState[$broadcastId] = $state;
    return $broadcastId;
}

    /**
     * Progress bar
     */
    private function progressBar(int $current, int $total): string {
        $len = 20;
		$filled = (int) round($current / max($total,1) * $len);
		return str_repeat('█',$filled).str_repeat('░',$len-$filled).' '.round(($current/max($total,1))*100).'%';
    }

    /**
     * Pause running broadcast
     */
    public function pause(string $id): void {
        if (isset($this->currentBroadcastState[$id])) {
            $this->currentBroadcastState[$id]['paused'] = true;
        }
    }

    /**
     * Resume running broadcast
     */
    public function resume(string $id): void {
        if (isset($this->currentBroadcastState[$id])) {
            $this->currentBroadcastState[$id]['paused'] = false;
        }
    }

    /**
     * cancel running broadcast
     */
    public function cancel(string $id): void {
        if (isset($this->currentBroadcastState[$id])) {
            $this->currentBroadcastState[$id]['cancel'] = true;
            $this->currentBroadcastState[$id]['inFlight'] = [];
        }
    }

    /**
     * Check if broadcast is paused
     */
    public function isPaused(string $id): bool {
        return $this->currentBroadcastState[$id]['paused'] ?? false;
    }

    /**
     * Check if broadcast is cancelled
     */
    public function isCancelled(string $id): bool {
        return $this->currentBroadcastState[$id]['cancel'] ?? false;
    }

    /**
     * Check if broadcast is active
     */
    public function isActive(?string $id = null): bool {
        if (!$id || !isset($this->currentBroadcastState[$id])) {
            return false;
        }

        $state = $this->currentBroadcastState[$id];

        if (!$state) return false;

        return (
            empty($state['done']) &&
            empty($state['cancel']) &&
            empty($state['paused'])
        );
    }

    /**
     * Check if there is a last broadcast message saved for deletion
     */
    public function hasLastBroadcast(): bool {
        foreach (glob(self::getDataDir() ."/*/lastBroadcast.txt") as $file) {
            if (file_exists($file) && trim(\Amp\File\read($file))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if there is a broadcast messages saved for deletion
     */
    public function hasAllBroadcast(): bool {
        foreach (glob(self::getDataDir() ."/*/messages.txt") as $file) {
            if (file_exists($file) && trim(\Amp\File\read($file))) {
                return true;
            }
        }
        return false;
    }

    /**
     * normalize broadcast state
     */
    private function normalizeBroadcastState(array $state): array {
        return [
            'sent'      => $state['sent'] ?? 0,
            'deleted'   => $state['deleted'] ?? 0,
            'unpin'     => $state['unpin'] ?? 0,
            'failed'    => $state['failed'] ?? 0,
            'flood'     => $state['flood'] ?? 0,

            'queue'     => $state['queue'] ?? null,
            'inFlight'  => $state['inFlight'] ?? [],

            'done'      => $state['done'] ?? false,
            'paused'    => $state['paused'] ?? false,
            'cancel'    => $state['cancel'] ?? false,

            'startedAt' => $state['startedAt'] ?? null,
        ];
    }

    /**
     * Get current broadcast progress
     *
     * @return array|null {
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
    */
    public function progress(?string $id = null): ?array {
        if (!$id || !isset($this->currentBroadcastState[$id])) {
            return null;
        }

        $state = $this->normalizeBroadcastState($this->currentBroadcastState[$id]);

        $sent    = (int)($state['sent'] ?? 0);
        $deleted = (int)($state['deleted'] ?? 0);
        $unpin   = (int)($state['unpin'] ?? 0);
        $failed  = (int)($state['failed'] ?? 0);
        $flood   = (int)($state['flood'] ?? 0);

        $processed = $sent + $deleted + $unpin + $failed;
        $success   = $sent + $deleted + $unpin;

        $pending = ($state['queue'] instanceof \SplQueue)
            ? $state['queue']->count()
            : 0;

        $total = $processed + $pending;

        $progressPercent = $total > 0
            ? round(($processed / $total) * 100, 2)
            : 0;

        return [
            'processed' => $processed,
            'success'   => $success,
            'failed'    => $failed,
            'pending'   => $pending,
            'flood'     => $flood,

            'progressPercent' => $progressPercent,

            'breakdown' => [
                'sent'    => $sent,
                'deleted' => $deleted,
                'unpin'   => $unpin,
            ],

            'done'      => (bool)($state['done'] ?? false),
            'paused'    => (bool)($state['paused'] ?? false),
            'cancel'    => (bool)($state['cancel'] ?? false),
            'startedAt' => $state['startedAt'] ?? null,
        ];
    }

    /**
     * Last broadcast data
     */
    public function lastBroadcastData(): string|false {
        $dir = self::getDataDir();

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $path = $dir . "/LastBrodDATA.txt";

        if (!file_exists($path)) {
            return false;
        }

        return \Amp\File\read($path);
    }

    /**
     * Filter peers
     * allowedTypes: all / users / groups / channels
     *
     * @return array {
     *   targets: array,        // filtered peers
     *   failed: int,          // count of failed
     *   total: int,          // count filtered peers
     * }
     */
    public function filterPeers(
	    array $allUsers,
        string $filterType = 'users'
	): array {

    $api = $this->api;

    $allowedTypes = [
        'all'      => ['user','chat','supergroup','channel'],
        'users'    => ['user'],
        'groups'   => ['chat','supergroup'],
        'channels' => ['channel'],
    ];

    $targets = [];
    $failedCount = 0;

    foreach ($allUsers as $peer) {
        try {
            $info = $api->getInfo($peer);
            $type = $info['type'] ?? 'user';
            if (in_array($type, $allowedTypes[$filterType] ?? ['user'], true)) {
                $targets[] = (string)$peer;
            }
        } catch (\Throwable) {
            if ($filterType === 'all') $targets[] = (string)$peer;
            else $failedCount++;
        }
    }

    return [
        'targets' => $targets,
        'failed'  => $failedCount,
        'total'   => count($targets)
    ];

    }

}
