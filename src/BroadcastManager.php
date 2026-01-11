<?php

declare(strict_types=1);

/**
 * Broadcast Manager
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

    /* ===== INIT STATUS ===== */
    $status = $api->messages->sendMessage([
        'peer' => $chatId,
        'message' => '⌛ GATHERING PEERS...',
        'parse_mode' => 'HTML'
    ]);
    $statusId = $api->extractMessageId($status);

    /* ===== FILTER TARGETS ===== */
    $allowedTypes = [
        'all'      => ['user','bot','chat','supergroup','channel'],
        'users'    => ['user','bot'],
        'groups'   => ['chat','supergroup'],
        'channels' => ['channel'],
    ];

    $targets = [];
    $failedCount = 0;

    foreach ($allUsers as $peer) {
        try {
            $info = $api->getInfo($peer);
            $type = $info['type'] ?? 'user';
            if (in_array($type, $allowedTypes[$filterType] ?? ['user','bot'], true)) {
                $targets[] = (string)$peer;
            }
        } catch (\Throwable) {
            if ($filterType === 'all') $targets[] = (string)$peer;
            else $failedCount++;
        }
    }

    $total = count($targets);

    /* ===== STATE ===== */
    $state = [
        'sent' => 0,
        'failed' => $failedCount,
        'queue' => new \SplQueue(),
        'inFlight' => [],
        'lastMessageIds' => [],
        'paused' => false,
        'cancel' => false,
        'done' => false,
        'startedAt' => microtime(true),
    ];

/* ===== SET CURRENT BROADCAST STATE FOR PAUSE/CANCEL ===== */
    $this->currentBroadcastState = &$state;

    foreach ($targets as $peer) {
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

            if ($text !== $last) {
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

    try { $api->messages->editMessage(['peer'=>$chatId,'id'=>$statusId,'message'=>$finalText,'parse_mode'=>'HTML']); } catch (\Throwable) {}
    try { \Amp\File\write(__DIR__.'/data/LastBrodDATA.txt',$finalText); } catch (\Throwable) {}

    foreach ($state['lastMessageIds'] as $peer=>$id) {
        $dir=__DIR__."/data/$peer";
        if(!is_dir($dir))@mkdir($dir,0777,true);
		try {
        $fh = \Amp\File\openFile("$dir/messages.txt", "a");
        $fh->write((string)$id . "\n");
        $fh->close();
        } catch (\Throwable) {}
        try{\Amp\File\write("$dir/lastBroadcast.txt",$id);}catch(\Throwable){}
    }

    return $state;
}

    /**
     * Delete last broadcast message for all users
     */
    public function deleteLastBroadcastForAll(
        array $allUsers,
        $chatId,
        int $concurrency = 20
    ): array {
    $api = $this->api;
    $total = count($allUsers);

    /* ===== STATE ===== */
    $state = [
        'deleted' => 0,
        'failed' => 0,
        'flood' => 0,
        'queue' => new \SplQueue(),
        'inFlight' => [],
        'done' => false,
        'cancel' => false,
        'startedAt' => microtime(true),
    ];

    foreach ($allUsers as $peer) {
        $state['queue']->enqueue([
            'peer' => (string)$peer,
            'attempts' => 0,
            'startedAt' => null,
            'availableAt' => 0
        ]);
    }

    /* ===== STATUS MESSAGE ===== */
    $status = $api->messages->sendMessage([
        'peer' => $chatId,
        'message' => "⌛ Deleting last broadcast...",
        'parse_mode' => 'HTML'
    ]);
    $statusId = $api->extractMessageId($status);

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

            if ($text !== $last) {
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
                    $file = __DIR__."/data/$peer/lastBroadcast.txt";
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
     * Delete all broadcasts message for all users
     */
/*
    public function deleteAllBroadcastsForAll(
        array $allUsers,
        $chatId,
        int $concurrency = 20
    ): array {
    $api = $this->api;
    $total = count($allUsers);

    $state = [
        'deleted' => 0,
        'failed' => 0,
        'flood' => 0,
        'queue' => new \SplQueue(),
        'inFlight' => [],
        'done' => false,
        'cancel' => false,
        'startedAt' => microtime(true),
    ];

    foreach ($allUsers as $peer) {
        $state['queue']->enqueue([
            'peer' => (string)$peer,
            'attempts' => 0,
            'startedAt' => null,
            'availableAt' => 0
        ]);
    }

    $status = $api->messages->sendMessage([
        'peer' => $chatId,
        'message' => "⌛ Deleting all broadcasts...",
        'parse_mode' => 'HTML'
    ]);
    $statusId = $api->extractMessageId($status);

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

                try {
                    $file = __DIR__."/data/$peer/messages.txt";
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

                            // ===== עדכון פרוגרס מיד =====
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

                            try {
                                $api->messages->editMessage([
                                    'peer' => $chatId,
                                    'id' => $statusId,
                                    'message' => $text,
                                    'parse_mode' => 'HTML'
                                ]);
                            } catch (\Throwable) {}

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
                    $state['deleted']++;
                    @unlink($file);
                    unset($state['inFlight'][$peer]);

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
*/

    public function deleteAllBroadcastsForAll(
        array $allUsers,
        $chatId,
        int $concurrency = 20
    ): array {
    $api = $this->api;
    $total = count($allUsers);

    $state = [
        'deleted' => 0,
        'failed' => 0,
        'flood' => 0,
        'queue' => new \SplQueue(),
        'inFlight' => [],
        'done' => false,
        'cancel' => false,
        'startedAt' => microtime(true),
    ];

    foreach ($allUsers as $peer) {
        $state['queue']->enqueue([
            'peer' => (string)$peer,
            'attempts' => 0,
            'startedAt' => null,
            'availableAt' => 0
        ]);
    }

    $status = $api->messages->sendMessage([
        'peer' => $chatId,
        'message' => "⌛ Deleting all broadcasts...",
        'parse_mode' => 'HTML'
    ]);
    $statusId = $api->extractMessageId($status);

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
                    $file = __DIR__."/data/$peer/messages.txt";
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

                    @unlink($file);
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

                    try {
                        $api->messages->editMessage([
                            'peer' => $chatId,
                            'id' => $statusId,
                            'message' => $text,
                            'parse_mode' => 'HTML'
                        ]);
                    } catch (\Throwable) {}

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
    public function unpinAllMessagesForAll(
        array $allUsers,
        $chatId,
        string $filterType = 'users',
        int $concurrency = 20
    ): array {
    $api = $this->api;

    /* ===== STATUS MESSAGE ===== */
    $status = $api->messages->sendMessage([
        'peer' => $chatId,
        'message' => '⌛ GATHERING PEERS...',
        'parse_mode' => 'HTML'
    ]);
    $statusId = $api->extractMessageId($status);

    /* ===== FILTER TARGETS ===== */
    $allowedTypesByFilter = [
        'all' => ['user','bot','chat','supergroup','channel'],
        'users' => ['user','bot'],
        'groups' => ['chat','supergroup'],
        'channels' => ['channel'],
    ];

    $targets = [];
    $failedCount = 0;

    foreach ($allUsers as $peer) {
        try {
            $info = $api->getInfo($peer);
            $type = $info['type'] ?? 'user';

            if (in_array($type, $allowedTypesByFilter[$filterType] ?? ['user','bot'], true)) {
                $targets[] = (string)$peer;
            }
        } catch (\Throwable) {
            if ($filterType === 'all') $targets[] = (string)$peer;
            else $failedCount++;
        }
    }

    $total = count($targets);

    /* ===== STATE ===== */
    $state = [
        'unpin' => 0,
        'failed' => $failedCount,
        'flood' => 0,
        'queue' => new \SplQueue(),
        'inFlight' => [],
        'done' => false,
        'cancel' => false,
        'startedAt' => microtime(true),
    ];

    foreach ($targets as $peer) {
        $state['queue']->enqueue([
            'peer' => $peer,
            'attempts' => 0,
            'startedAt' => null,
            'availableAt' => 0
        ]);
    }

    $api->messages->editMessage([
        'peer' => $chatId,
        'id' => $statusId,
        'message' => "📌⌛ Starting unpin for all subscribers...",
        'parse_mode' => 'HTML'
    ]);

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

            if ($text !== $last) {
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
     * Check if there is a broadcast messages saved for deletion
     */
    public function hasAllBroadcast(): bool {
        foreach (glob(__DIR__."/data/*/messages.txt") as $file) {
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

    /**
     * Last broadcast data
     */
    public function lastBroadcastData(): string|false {
            if (file_exists(__DIR__."/data/LastBrodDATA.txt")) {
                return \Amp\File\read(__DIR__."/data/LastBrodDATA.txt");
            }else{
                return false;
            }
    }

}
